<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\PrereleaseAvroValueMigration;
use Workflow\V2\WorkflowStub;

final class V2PrereleaseAvroMigrationCommandTest extends TestCase
{
    private const WRAPPER_SCHEMA = '{"type":"record","name":"Payload","namespace":"durable_workflow","fields":[{"name":"json","type":"string"},{"name":"version","type":"int","default":1}]}';

    public function testCommandMigratesInlineAndExternalHistoryCopiesThenReplaysTheRetainedRun(): void
    {
        config()->set('workflows.serializer', 'avro');
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $temporaryRoot = sys_get_temp_dir() . '/dw-avro-migration-' . Str::ulid();
        $externalRoot = $temporaryRoot . '/external';
        $backupPath = $temporaryRoot . '/backup.json';
        $replayDirectory = $temporaryRoot . '/replay';
        mkdir($temporaryRoot);
        $this->beforeApplicationDestroyed(static function () use ($temporaryRoot): void {
            (new Filesystem())->deleteDirectory($temporaryRoot);
            ExternalPayloadStorage::flushVerifiedCache();
        });

        $driver = new LocalFilesystemExternalPayloadStorage($externalRoot);

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'prerelease-avro-migration');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->bindExternalPayloadPolicy($driver);

        $run = WorkflowRun::query()->findOrFail($runId);
        $currentArguments = $run->arguments;
        $this->assertIsString($currentArguments);
        $legacyArguments = self::legacyBlob(Avro::unserialize($currentArguments));
        $run->forceFill([
            'arguments' => $legacyArguments,
        ])->save();
        WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->where('payload_codec', 'avro')
            ->where('payload', $currentArguments)
            ->update([
                'payload' => $legacyArguments,
            ]);

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();
        $currentResult = $execution->result;
        $this->assertIsString($currentResult);
        $legacyResult = self::legacyBlob(Avro::unserialize($currentResult));
        $oldReference = ExternalPayloadStorage::store($driver, $legacyResult, 'avro');
        $oldEnvelope = [
            'codec' => 'avro',
            'external_storage' => $oldReference->toArray(),
        ];
        $execution->forceFill([
            'result' => ExternalPayloads::encodeStoredEnvelope($oldEnvelope),
        ])->save();

        $inlineHistoryCopies = 0;
        $externalHistoryCopies = 0;
        foreach (WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get() as $event) {
            $payload = $event->payload;
            $this->assertIsArray($payload);
            $payload = self::replaceExactValue(
                $payload,
                $currentArguments,
                $legacyArguments,
                $inlineHistoryCopies,
            );
            $payload = self::replaceExactValue($payload, $currentResult, $oldEnvelope, $externalHistoryCopies);
            $event->forceFill([
                'payload' => $payload,
            ])->save();
        }
        $this->assertGreaterThan(0, $inlineHistoryCopies);
        $this->assertGreaterThan(0, $externalHistoryCopies);

        $exit = Artisan::call('workflow:v2:migrate-prerelease-avro', [
            '--backup' => $backupPath,
            '--replay-export-dir' => $replayDirectory,
        ]);
        $diagnostic = Artisan::output();
        $reportPath = $replayDirectory . '/replay-report.json';
        if (is_file($reportPath)) {
            $diagnostic .= "\n" . file_get_contents($reportPath);
        }
        $bundlePath = $replayDirectory . '/' . $runId . '.json';
        if (is_file($bundlePath)) {
            $bundle = self::readJson($bundlePath);
            $diagnostic .= "\n" . json_encode([
                'activities' => $bundle['activities'] ?? null,
                'payload_manifest' => $bundle['payload_manifest'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $this->assertSame(0, $exit, $diagnostic);
        clearstatcache(true, $backupPath);
        clearstatcache(true, $replayDirectory);
        $this->assertSame(0600, fileperms($backupPath) & 0777);
        $this->assertSame(0700, fileperms($replayDirectory) & 0777);

        $run->refresh();
        $this->assertSame(['Ada'], Avro::unserialize((string) $run->arguments));
        $this->assertFalse(PrereleaseAvroValueMigration::isLegacyBlob((string) $run->arguments));
        self::assertTypedFrame((string) $run->arguments);

        $execution->refresh();
        $newEnvelope = ExternalPayloads::storedEnvelope((string) $execution->result);
        $this->assertNotNull($newEnvelope);
        $newReference = ExternalPayloadReference::fromArray($newEnvelope['external_storage']);
        $this->assertNotSame($oldReference->uri, $newReference->uri);
        $this->assertNotSame($oldReference->sha256, $newReference->sha256);
        $this->assertNotSame($oldReference->sizeBytes, $newReference->sizeBytes);
        $this->assertSame($legacyResult, $driver->get($oldReference->uri));
        $newExternalPayload = $driver->get($newReference->uri);
        $this->assertSame('Hello, Ada!', Avro::unserialize($newExternalPayload));
        self::assertTypedFrame($newExternalPayload);

        $typedHistoryCopies = [
            'inline' => 0,
            'external' => 0,
        ];
        foreach (WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->get() as $event) {
            $payload = $event->payload;
            $this->assertIsArray($payload);
            $inspection = PrereleaseAvroValueMigration::inspectHistoryPayload(
                $payload,
                is_string($run->namespace) ? $run->namespace : null,
            );
            $this->assertSame(0, $inspection['legacy_count']);
            $eventTypedCopies = $this->countTypedHistoryCopies($payload, $driver);
            $typedHistoryCopies['inline'] += $eventTypedCopies['inline'];
            $typedHistoryCopies['external'] += $eventTypedCopies['external'];
        }
        $this->assertGreaterThanOrEqual($inlineHistoryCopies, $typedHistoryCopies['inline']);
        $this->assertGreaterThanOrEqual($externalHistoryCopies, $typedHistoryCopies['external']);

        $backup = self::readJson($backupPath);
        $this->assertSame('durable-workflow.prerelease-avro-migration-backup/v1', $backup['schema']);
        $this->assertNotEmpty(array_filter(
            $backup['records'],
            static fn (mixed $record): bool => is_array($record)
                && ($record['kind'] ?? null) === 'history_payload'
                && ($record['external_backups'] ?? []) !== [],
        ));

        $report = self::readJson($reportPath);
        $this->assertSame('ok', $report['verdict']);
        $this->assertSame(1, $report['evidence']['replay_checked_count']);
        $this->assertSame(1, $report['summary']['ok']);

        $collisionPath = $temporaryRoot . '/existing-backup.json';
        file_put_contents($collisionPath, "do-not-overwrite\n");
        chmod($collisionPath, 0600);
        $run->forceFill([
            'arguments' => $legacyArguments,
        ])->save();

        $collisionExit = Artisan::call('workflow:v2:migrate-prerelease-avro', [
            '--backup' => $collisionPath,
            '--replay-export-dir' => $temporaryRoot . '/collision-replay',
        ]);
        $this->assertSame(1, $collisionExit, Artisan::output());
        $this->assertSame("do-not-overwrite\n", file_get_contents($collisionPath));
        $this->assertDirectoryDoesNotExist($temporaryRoot . '/collision-replay');
    }

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    private function bindExternalPayloadPolicy(ExternalPayloadStorageDriver $driver): void
    {
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
                ) {
                }

                public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
                {
                    return $this->driver;
                }

                public function thresholdBytesFor(?string $namespace): ?int
                {
                    return 1;
                }
            },
        );
    }

    private static function legacyBlob(mixed $value): string
    {
        $io = new AvroStringIO();
        $io->write("\x00");
        (new AvroIODatumWriter(Avro::parseSchema(self::WRAPPER_SCHEMA)))->write(
            [
                'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'version' => 1,
            ],
            new AvroIOBinaryEncoder($io),
        );

        return base64_encode($io->string());
    }

    private static function replaceExactValue(
        mixed $value,
        string $target,
        mixed $replacement,
        int &$replacements,
    ): mixed {
        if ($value === $target) {
            $replacements++;

            return $replacement;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::replaceExactValue($item, $target, $replacement, $replacements);
        }

        return $value;
    }

    /**
     * @return array{inline: int, external: int}
     */
    private function countTypedHistoryCopies(mixed $value, ExternalPayloadStorageDriver $driver): array
    {
        if (is_string($value)) {
            if (ExternalPayloads::isStoredReference($value)) {
                $envelope = ExternalPayloads::storedEnvelope($value);
                $this->assertNotNull($envelope);
                $reference = ExternalPayloadReference::fromArray($envelope['external_storage']);
                self::assertTypedFrame($driver->get($reference->uri));

                return [
                    'inline' => 0,
                    'external' => 1,
                ];
            }
            $decoded = base64_decode($value, true);

            return [
                'inline' => is_string($decoded) && str_starts_with($decoded, "\xc3\x01") ? 1 : 0,
                'external' => 0,
            ];
        }
        if (! is_array($value)) {
            return [
                'inline' => 0,
                'external' => 0,
            ];
        }
        if (isset($value['external_storage']) && is_array($value['external_storage'])) {
            $reference = ExternalPayloadReference::fromArray($value['external_storage']);
            self::assertTypedFrame($driver->get($reference->uri));

            return [
                'inline' => 0,
                'external' => 1,
            ];
        }

        $counts = [
            'inline' => 0,
            'external' => 0,
        ];
        foreach ($value as $item) {
            $itemCounts = $this->countTypedHistoryCopies($item, $driver);
            $counts['inline'] += $itemCounts['inline'];
            $counts['external'] += $itemCounts['external'];
        }

        return $counts;
    }

    private static function assertTypedFrame(string $payload): void
    {
        $decoded = base64_decode($payload, true);
        self::assertIsString($decoded);
        self::assertStringStartsWith("\xc3\x01", $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read JSON fixture [%s].', $path));
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
