<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\PrereleaseAvroValueMigration;

/**
 * Back up and rewrite retained prerelease JSON-in-Avro rows exactly once.
 */
final class V2MigratePrereleaseAvroCommand extends Command
{
    /**
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'workflow_runs' => [
            'arguments' => 'payload_codec',
            'output' => 'output_payload_codec',
        ],
        'workflow_commands' => [
            'payload' => 'payload_codec',
        ],
        'activity_executions' => [
            'arguments' => 'payload_codec',
            'result' => 'payload_codec',
        ],
        'workflow_signal_records' => [
            'arguments' => 'payload_codec',
        ],
        'workflow_updates' => [
            'arguments' => 'payload_codec',
            'result' => 'payload_codec',
        ],
    ];

    protected $signature = 'workflow:v2:migrate-prerelease-avro
        {--backup= : New JSON backup path; the command refuses to overwrite it}
        {--replay-export-dir= : New directory for migrated history bundles and the replay report}
        {--dry-run : Inventory affected rows without writing a backup or database changes}';

    protected $description = 'Back up and migrate prerelease Avro wrapper payloads to the fixed typed Value schema';

    public function handle(): int
    {
        $configuredConnection = config('workflows.storage.connection');
        $connection = DB::connection(is_string($configuredConnection) ? $configuredConnection : null);
        $records = $this->inventory($connection);
        $count = array_sum(array_column($records, 'legacy_count'));
        $this->components->info("Found {$count} prerelease Avro payload field(s).");

        if ((bool) $this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        $backupPath = $this->option('backup');
        if (! is_string($backupPath) || trim($backupPath) === '') {
            $this->components->error('--backup is required when affected rows exist.');

            return self::FAILURE;
        }
        $replayDirectory = $this->option('replay-export-dir');
        if (! is_string($replayDirectory) || trim($replayDirectory) === '') {
            $this->components->error('--replay-export-dir is required when affected rows exist.');

            return self::FAILURE;
        }
        if (file_exists($replayDirectory)) {
            $this->components->error('Replay export directory already exists; refusing to reuse it.');

            return self::FAILURE;
        }

        $backup = [
            'schema' => 'durable-workflow.prerelease-avro-migration-backup/v1',
            'created_at' => now()
                ->toIso8601String(),
            'connection' => $connection->getName(),
            'records' => $records,
        ];
        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (! $this->writePrivateBackup($backupPath, $json)) {
            return self::FAILURE;
        }
        if (! mkdir($replayDirectory, 0700, true) || ! chmod($replayDirectory, 0700)) {
            throw new RuntimeException('Unable to create prerelease Avro replay export directory.');
        }

        $connection->transaction(static function () use ($connection, $records): void {
            foreach ($records as $record) {
                if ($record['kind'] === 'history_payload') {
                    $conversion = PrereleaseAvroValueMigration::convertHistoryPayload(
                        $record['history_payload'],
                        $record['namespace'],
                    );
                    $remaining = PrereleaseAvroValueMigration::inspectHistoryPayload(
                        $conversion['payload'],
                        $record['namespace'],
                    );
                    if (
                        $conversion['legacy_count'] !== $record['legacy_count']
                        || $remaining['legacy_count'] !== 0
                    ) {
                        throw new RuntimeException(
                            'Prerelease Avro history snapshot migration left a legacy payload copy.',
                        );
                    }
                    $connection->table($record['table'])
                        ->where('id', $record['id'])
                        ->update([
                            $record['column'] => json_encode(
                                $conversion['payload'],
                                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                            ),
                        ]);

                    continue;
                }

                $converted = PrereleaseAvroValueMigration::convert($record['legacy_blob']);
                if ($record['external']) {
                    $converted = ExternalPayloads::replaceStoredReference(
                        $record['blob'],
                        $converted,
                        $record['namespace'],
                    );
                }
                $connection->table($record['table'])
                    ->where('id', $record['id'])
                    ->update([
                        $record['column'] => $converted,
                    ]);
            }
        });

        $runIds = array_values(array_unique(array_filter(
            array_column($records, 'run_id'),
            static fn (mixed $runId): bool => is_string($runId) && $runId !== '',
        )));
        foreach ($runIds as $runId) {
            $exit = $this->call('workflow:v2:history-export', [
                'target' => $runId,
                '--run' => true,
                '--output' => $replayDirectory . '/' . $runId . '.json',
            ]);
            if ($exit !== self::SUCCESS) {
                $this->components->error(
                    'Migration completed from its backup, but a retained history export failed; release remains blocked.',
                );

                return self::FAILURE;
            }
        }

        if ($runIds !== []) {
            $exit = $this->call('workflow:v2:replay-simulate', [
                'directory' => $replayDirectory,
                '--strict-warnings' => true,
                '--output' => $replayDirectory . '/replay-report.json',
            ]);
            if ($exit !== self::SUCCESS) {
                $this->components->error(
                    'Migration completed from its backup, but replay verification failed; release remains blocked.',
                );

                return self::FAILURE;
            }
        }

        $this->components->info(sprintf(
            'Migrated %d field(s); backup sha256=%s; replay-verified histories=%d.',
            $count,
            hash_file('sha256', $backupPath),
            count($runIds),
        ));

        return self::SUCCESS;
    }

    private function writePrivateBackup(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            $this->components->error('Backup path already exists or cannot be created; refusing to overwrite it.');

            return false;
        }

        $complete = false;
        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Unable to restrict prerelease Avro migration backup permissions.');
            }

            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Unable to write the complete prerelease Avro migration backup.');
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the prerelease Avro migration backup.');
            }
            if (! fclose($handle)) {
                $handle = null;
                throw new RuntimeException('Unable to close the prerelease Avro migration backup.');
            }
            $handle = null;
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600) {
                throw new RuntimeException('Prerelease Avro migration backup permissions are not private.');
            }
            $complete = true;

            return true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! $complete && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return list<array{
     *     table: string,
     *     kind: 'column'|'history_payload',
     *     id: string,
     *     run_id: string|null,
     *     column: string,
     *     codec_column: string,
     *     namespace: string|null,
     *     external: bool,
     *     blob: string,
     *     legacy_blob: string,
     *     legacy_count: int,
     *     history_payload?: array<string, mixed>,
     *     external_backups?: list<array{path: string, envelope: array<string, mixed>, legacy_blob: string}>
     * }>
     */
    private function inventory(ConnectionInterface $connection): array
    {
        $records = [];
        $schema = $connection->getSchemaBuilder();
        foreach (self::COLUMNS as $table => $columns) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'id')) {
                continue;
            }
            foreach ($columns as $column => $codecColumn) {
                if (! $schema->hasColumn($table, $column) || ! $schema->hasColumn($table, $codecColumn)) {
                    continue;
                }
                $query = $connection->table($table)
                    ->where($codecColumn, 'avro')
                    ->whereNotNull($column);
                $select = ['id', $column];
                if ($table === 'workflow_runs') {
                    if ($schema->hasColumn($table, 'namespace')) {
                        $select[] = 'namespace';
                    }
                } elseif ($schema->hasColumn($table, 'workflow_run_id')) {
                    $select[] = 'workflow_run_id';
                    foreach (['resolved_workflow_run_id', 'requested_workflow_run_id'] as $runColumn) {
                        if ($schema->hasColumn($table, $runColumn)) {
                            $select[] = $runColumn;
                        }
                    }
                }
                if ($table === 'workflow_commands' && $schema->hasColumn($table, 'context')) {
                    $select[] = 'context';
                }

                foreach ($query->get($select) as $row) {
                    $blob = $row->{$column};
                    if (! is_string($blob)) {
                        continue;
                    }
                    $namespace = $this->namespaceForRow($connection, $table, $row);
                    $external = ExternalPayloads::isStoredReference($blob);
                    $legacyBlob = $external
                        ? ExternalPayloads::resolveStoredPayload($blob, 'avro', $namespace)
                        : $blob;
                    if (! PrereleaseAvroValueMigration::isLegacyBlob($legacyBlob)) {
                        continue;
                    }
                    $records[] = [
                        'kind' => 'column',
                        'table' => $table,
                        'id' => (string) $row->id,
                        'run_id' => $this->runIdForRow($table, $row),
                        'column' => $column,
                        'codec_column' => $codecColumn,
                        'namespace' => $namespace,
                        'external' => $external,
                        'blob' => $blob,
                        'legacy_blob' => $legacyBlob,
                        'legacy_count' => 1,
                    ];
                }
            }
        }

        if (
            $schema->hasTable('workflow_history_events')
            && $schema->hasColumn('workflow_history_events', 'id')
            && $schema->hasColumn('workflow_history_events', 'payload')
            && $schema->hasColumn('workflow_history_events', 'workflow_run_id')
        ) {
            foreach (
                $connection->table('workflow_history_events')
                    ->whereNotNull('payload')
                    ->get(['id', 'workflow_run_id', 'payload']) as $row
            ) {
                $payload = $row->payload;
                if (is_string($payload)) {
                    $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if (! is_array($payload)) {
                    continue;
                }
                $namespace = $this->namespaceForRow($connection, 'workflow_history_events', $row);
                $inspection = PrereleaseAvroValueMigration::inspectHistoryPayload($payload, $namespace);
                if ($inspection['legacy_count'] === 0) {
                    continue;
                }
                $records[] = [
                    'kind' => 'history_payload',
                    'table' => 'workflow_history_events',
                    'id' => (string) $row->id,
                    'run_id' => $this->runIdForRow('workflow_history_events', $row),
                    'column' => 'payload',
                    'codec_column' => '',
                    'namespace' => $namespace,
                    'external' => $inspection['external_backups'] !== [],
                    'blob' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'legacy_blob' => '',
                    'legacy_count' => $inspection['legacy_count'],
                    'history_payload' => $payload,
                    'external_backups' => $inspection['external_backups'],
                ];
            }
        }

        return $records;
    }

    private function runIdForRow(string $table, object $row): ?string
    {
        if ($table === 'workflow_runs') {
            return is_string($row->id ?? null) ? $row->id : null;
        }

        foreach (['workflow_run_id', 'resolved_workflow_run_id', 'requested_workflow_run_id'] as $field) {
            if (is_string($row->{$field} ?? null) && $row->{$field} !== '') {
                return $row->{$field};
            }
        }

        return null;
    }

    private function namespaceForRow(ConnectionInterface $connection, string $table, object $row): ?string
    {
        if ($table === 'workflow_runs') {
            return is_string($row->namespace ?? null) ? $row->namespace : null;
        }

        $runId = $this->runIdForRow($table, $row);
        if (is_string($runId) && $runId !== '') {
            $namespace = $connection->table('workflow_runs')
                ->where('id', $runId)
                ->value('namespace');

            return is_string($namespace) ? $namespace : null;
        }

        $context = $row->context ?? null;
        if (is_string($context)) {
            $context = json_decode($context, true);
        }
        if (is_array($context)) {
            $namespace = $context['server']['namespace'] ?? $context['namespace'] ?? null;

            return is_string($namespace) ? $namespace : null;
        }

        return null;
    }
}
