<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2EmbeddedReplayRegressionCorpusTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/ReplayRegression';

    private int $workflowNumber = 0;

    public function testFixturesExecuteThroughDeclaredReplayConsumers(): void
    {
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();

        foreach ($this->fixtures() as $fixture) {
            $this->executeColdReplayFixture($fixture);

            $consumers = $fixture['consumers'] ?? ['workflow-fiber-runner'];
            if (in_array('embedded-history-import', $consumers, true)) {
                $this->assertHistoryImportMetadataRoundTrips($fixture);
            }

            $embeddedConsumers = array_intersect(['query-state-replayer', 'workflow-executor'], $consumers);

            if ($embeddedConsumers === []) {
                continue;
            }

            $this->assertArrayHasKey(
                'expected_failure',
                $fixture,
                "{$fixture['id']} embedded replay evidence must declare its failure.",
            );
            $mismatches = [];

            if (in_array('query-state-replayer', $consumers, true)) {
                $run = $this->createRunFromFixture($fixture);
                $operation = static fn (): mixed => (new QueryStateReplayer())->replay(
                    $run->fresh(['historyEvents']),
                );
                $mismatch = $this->replayFailureMismatch($operation, $fixture, 'QueryStateReplayer');
                if ($mismatch !== null) {
                    $mismatches[] = $mismatch;
                }
            }

            if (in_array('workflow-executor', $consumers, true)) {
                $run = $this->createRunFromFixture($fixture);
                $this->runReadyWorkflowTask($run);

                /** @var WorkflowFailure|null $failure */
                $failure = WorkflowFailure::query()
                    ->where('workflow_run_id', $run->id)
                    ->latest('created_at')
                    ->first();

                if ($failure === null) {
                    $mismatches[] = "{$fixture['id']} was accepted by WorkflowExecutor instead of failing closed.";
                } elseif ($failure->exception_class !== $fixture['expected_failure']['exception']) {
                    $mismatches[] = "{$fixture['id']} produced the wrong WorkflowExecutor failure "
                        . "[{$failure->exception_class}].";
                }
            }

            $this->assertSame([], $mismatches, implode(' ', $mismatches));
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertHistoryImportMetadataRoundTrips(array $fixture): void
    {
        $metadata = $fixture['history_import_metadata'];
        $run = $this->createRunFromFixture($fixture);

        foreach ($metadata['memo'] as $key => $value) {
            $memo = new WorkflowMemo([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key,
                'upserted_at_sequence' => $run->last_history_sequence,
                'inherited_from_parent' => false,
            ]);
            $memo->setValue($value);
            $memo->save();
        }

        foreach ($metadata['search_attributes'] as $key => $value) {
            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key,
                'upserted_at_sequence' => $run->last_history_sequence,
                'inherited_from_parent' => false,
            ]);
            $attribute->setTypedValueWithInference($value);
            $attribute->save();
        }

        $bundle = HistoryExport::forRun($run->fresh());
        $runId = $bundle['workflow']['run_id'];
        $this->assertEquals($metadata['memo'], $bundle['workflow']['memo']);
        $this->assertEquals($metadata['search_attributes'], $bundle['workflow']['search_attributes']);

        $this->clearWorkflowState();
        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);
        $this->assertNotContains(
            'payload_codec.unsupported',
            array_column($report['eligibility']['errors'], 'rule'),
        );

        /** @var WorkflowRun $importedRun */
        $importedRun = WorkflowRun::query()->findOrFail($runId);
        $roundTrip = HistoryExport::forRun($importedRun->fresh());

        $this->assertEquals($metadata['memo'], $roundTrip['workflow']['memo']);
        $this->assertEquals($metadata['search_attributes'], $roundTrip['workflow']['search_attributes']);
    }

    private function clearWorkflowState(): void
    {
        foreach ([
            'workflow_run_summaries',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_timer_entries',
            'workflow_run_lineage_entries',
            'workflow_search_attributes',
            'workflow_memos',
            'workflow_history_events',
            'workflow_tasks',
            'activity_attempts',
            'activity_executions',
            'workflow_run_timers',
            'workflow_failures',
            'workflow_links',
            'workflow_signal_records',
            'workflow_updates',
            'workflow_commands',
            'workflow_runs',
            'workflow_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function executeColdReplayFixture(array $fixture): void
    {
        $workflow = $fixture['workflow'];
        $workflowClass = $workflow['type'];

        $this->assertIsString($workflowClass);
        $this->assertTrue(
            is_a($workflowClass, Workflow::class, true),
            sprintf('Replay fixture workflow [%s] must be an autoloadable V2 workflow.', $workflowClass),
        );

        if (isset($fixture['expected_failure'])) {
            $this->assertReplayFailure(
                static fn (): WorkflowStep => WorkflowFiberRunner::forClass(
                    $workflowClass,
                    'regression-corpus-' . $fixture['id'],
                    'regression-corpus-run-' . $fixture['id'],
                    $workflow['arguments'],
                    $workflow['payload_codec'],
                    $fixture['history'],
                )->step(),
                $fixture,
                'WorkflowFiberRunner',
            );

            return;
        }

        $runner = WorkflowFiberRunner::forClass(
            $workflowClass,
            'regression-corpus-' . $fixture['id'],
            'regression-corpus-run-' . $fixture['id'],
            $workflow['arguments'],
            $workflow['payload_codec'],
            $fixture['history'] ?? [],
        );
        $step = $runner->step();

        if (isset($fixture['command_sequence'])) {
            foreach ($fixture['command_sequence'] as $index => $expectedStep) {
                $this->assertStepMatches($expectedStep, $step, "{$fixture['id']} command step {$index}");

                if ($index < count($fixture['command_sequence']) - 1) {
                    $step = $runner->step($expectedStep['resume_with']);
                }
            }
        }

        $this->assertStepMatches($fixture['expected'], $step, "{$fixture['id']} final outcome");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtures(): array
    {
        $paths = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        sort($paths);

        return array_map(
            static fn (string $path): array => json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $paths,
        );
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function createRunFromFixture(array $fixture): WorkflowRun
    {
        $workflow = $fixture['workflow'];
        $stub = WorkflowStub::make(
            $workflow['type'],
            sprintf('regression-corpus-embedded-%d', ++$this->workflowNumber),
        );
        $stub->start(...$workflow['arguments']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($stub->runId());

        foreach ($fixture['history'] as $event) {
            $eventType = HistoryEventType::from($event['event_type']);
            if ($eventType === HistoryEventType::WorkflowStarted) {
                continue;
            }

            WorkflowHistoryEvent::record($run, $eventType, $event['payload']);
        }

        return $run;
    }

    /**
     * @param callable(): mixed $operation
     * @param array<string, mixed> $fixture
     */
    private function assertReplayFailure(callable $operation, array $fixture, string $consumer): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assertSame(
                $fixture['expected_failure']['exception'],
                $exception::class,
                "{$fixture['id']} produced the wrong {$consumer} failure.",
            );

            return;
        }

        $this->fail("{$fixture['id']} was accepted by {$consumer} instead of failing closed.");
    }

    /**
     * @param callable(): mixed $operation
     * @param array<string, mixed> $fixture
     */
    private function replayFailureMismatch(callable $operation, array $fixture, string $consumer): ?string
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            if ($exception::class === $fixture['expected_failure']['exception']) {
                return null;
            }

            $exceptionClass = $exception::class;

            return "{$fixture['id']} produced the wrong {$consumer} failure [{$exceptionClass}].";
        }

        return "{$fixture['id']} was accepted by {$consumer} instead of failing closed.";
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertStepMatches(array $expected, WorkflowStep $actual, string $context): void
    {
        $this->assertSame($expected['completed'], $actual->completed, "{$context} completion mismatch.");
        $this->assertSame($expected['result'], $actual->result, "{$context} result mismatch.");
        $this->assertCount(count($expected['commands']), $actual->commands, "{$context} command count mismatch.");

        foreach ($expected['commands'] as $index => $expectedCommand) {
            $this->assertArrayContains($expectedCommand, $actual->commands[$index], "{$context} command {$index}");
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertArrayContains(array $expected, array $actual, string $context): void
    {
        foreach ($expected as $key => $expectedValue) {
            $this->assertArrayHasKey($key, $actual, "{$context} is missing [{$key}].");

            if (is_array($expectedValue) && is_array($actual[$key])) {
                $this->assertArrayContains($expectedValue, $actual[$key], "{$context}.{$key}");
                continue;
            }

            $this->assertSame($expectedValue, $actual[$key], "{$context}.{$key} mismatch.");
        }
    }

    private function runReadyWorkflowTask(WorkflowRun $run): void
    {
        $this->bindNoOpHistoryProjection();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function bindNoOpHistoryProjection(): void
    {
        $this->app->instance(HistoryProjectionRole::class, new class() implements HistoryProjectionRole {
            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                return new WorkflowRunSummary();
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->projectRun($run);
            }
        });
    }
}
