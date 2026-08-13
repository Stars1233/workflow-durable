<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestReplayMapOrderWorkflow;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;

final class ReplayRegressionCorpusTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/ReplayRegression';

    private const FIXTURE_SCHEMA = 'durable-workflow.replay-regression/v1';

    private const REPLAY_CONSUMERS = ['query-state-replayer', 'workflow-executor', 'workflow-fiber-runner'];

    /**
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('replayRegressionFixtures')]
    public function testFixtureExecutesThroughColdReplayRunner(array $fixture): void
    {
        $workflow = $fixture['workflow'];
        $workflowClass = $workflow['type'];

        $this->assertIsString($workflowClass);
        $this->assertTrue(
            is_a($workflowClass, Workflow::class, true),
            sprintf('Replay fixture workflow [%s] must be an autoloadable V2 workflow.', $workflowClass),
        );

        if (isset($fixture['expected_failure'])) {
            try {
                WorkflowFiberRunner::forClass(
                    $workflowClass,
                    'regression-corpus-' . $fixture['id'],
                    'regression-corpus-run-' . $fixture['id'],
                    $workflow['arguments'],
                    $workflow['payload_codec'],
                    $fixture['history'],
                )->step();
            } catch (Throwable $exception) {
                $this->assertSame($fixture['expected_failure']['exception'], $exception::class);
                if ($fixture['expected_failure']['type'] === 'unsupported_payload_codec') {
                    $this->assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
                    $this->assertStringContainsString('HTTP document transport', $exception->getMessage());
                }

                return;
            }

            $this->fail("{$fixture['id']} did not reject its malformed replay history.");
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

    public function testRawBlobsAndInlineEnvelopesProduceTheSameWorkflowResult(): void
    {
        $fixtures = self::replayRegressionFixtures();
        $fixture = $fixtures['workflow-fiber-activity-history-sequence'][0];
        $codec = 'avro';
        $this->assertSame($codec, $fixture['workflow']['payload_codec']);

        $blob = Serializer::serializeWithCodec($codec, 'Hello, Ada!');
        $rawHistory = $fixture['history'];
        $rawHistory[1]['payload']['payload_codec'] = $codec;
        $rawHistory[1]['payload']['result'] = $blob;
        $envelopeHistory = $rawHistory;
        unset($envelopeHistory[1]['payload']['payload_codec']);
        $envelopeHistory[1]['payload']['result'] = [
            'codec' => $codec,
            'blob' => $blob,
        ];

        $fromRawBlob = $this->replayStep($fixture, $rawHistory, "{$codec}-raw");
        $fromInlineEnvelope = $this->replayStep($fixture, $envelopeHistory, "{$codec}-envelope");

        $this->assertStepMatches($fixture['expected'], $fromRawBlob, "Raw {$codec} history");
        $this->assertStepMatches($fixture['expected'], $fromInlineEnvelope, "Inline {$codec} history");
        $this->assertSame(
            $fromRawBlob->result,
            $fromInlineEnvelope->result,
            "Inline {$codec} history produced a different workflow result.",
        );
    }

    public function testAlternateAvroMapOrdersRemainObservableToTheRunner(): void
    {
        $golden = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expectedResults = [
            [
                'top_level_keys' => ['outer', 'tail'],
                'nested_keys' => ['left', 'right'],
            ],
            [
                'top_level_keys' => ['tail', 'outer'],
                'nested_keys' => ['right', 'left'],
            ],
        ];

        foreach ($golden['alternate_map_orders'][0]['wire_base64'] as $index => $blob) {
            $step = WorkflowFiberRunner::forClass(
                TestReplayMapOrderWorkflow::class,
                'alternate-avro-map-order',
                'alternate-avro-map-order-run',
                [],
                'avro',
                [[
                    'sequence' => 1,
                    'event_type' => 'WorkflowStarted',
                    'payload' => [],
                ], [
                    'sequence' => 7,
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'sequence' => 7,
                        'payload_codec' => 'avro',
                        'result' => $blob,
                    ],
                ]],
            )->step();

            self::assertTrue($step->completed);
            self::assertSame('complete_workflow', $step->command['type']);
            self::assertSame($expectedResults[$index], $step->result);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function replayRegressionFixtures(): array
    {
        $paths = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        sort($paths);

        self::assertNotSame([], $paths, 'Expected at least one executable replay-regression fixture.');

        $fixtures = [];
        foreach ($paths as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($fixture);
            self::assertSame(self::FIXTURE_SCHEMA, $fixture['fixture_schema'] ?? null);
            self::assertIsString($fixture['id'] ?? null);
            self::assertContains('php', $fixture['bindings'] ?? []);
            self::assertIsArray($fixture['workflow'] ?? null);
            self::assertIsArray($fixture['workflow']['arguments'] ?? null);
            self::assertIsString($fixture['workflow']['payload_codec'] ?? null);
            $consumers = $fixture['consumers'] ?? ['workflow-fiber-runner'];
            self::assertIsArray($consumers);
            self::assertNotSame([], $consumers);
            self::assertSame(array_values(array_unique($consumers)), $consumers);
            foreach ($consumers as $consumer) {
                self::assertContains($consumer, self::REPLAY_CONSUMERS);
            }
            self::assertContains('workflow-fiber-runner', $consumers);
            $hasExpected = isset($fixture['expected']);
            $hasExpectedFailure = isset($fixture['expected_failure']);
            self::assertNotSame(
                $hasExpected,
                $hasExpectedFailure,
                "{$fixture['id']} must provide exactly one expected outcome.",
            );
            if ($hasExpected) {
                self::assertIsArray($fixture['expected']);
            } else {
                self::assertIsArray($fixture['expected_failure']);
                $failureFields = array_keys($fixture['expected_failure']);
                sort($failureFields);
                self::assertSame(['exception', 'type'], $failureFields);
                self::assertNotSame('', $fixture['expected_failure']['exception'] ?? '');
                self::assertNotSame('', $fixture['expected_failure']['type'] ?? '');
            }

            $hasHistory = isset($fixture['history']);
            $hasCommandSequence = isset($fixture['command_sequence']);
            self::assertNotSame(
                $hasHistory,
                $hasCommandSequence,
                "{$fixture['id']} must provide exactly one executable replay input.",
            );

            $fixtures[$fixture['id']] = [$fixture];
        }

        return $fixtures;
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
     * @param array<string, mixed> $fixture
     * @param list<array<string, mixed>> $history
     */
    private function replayStep(array $fixture, array $history, string $id): WorkflowStep
    {
        $workflow = $fixture['workflow'];

        return WorkflowFiberRunner::forClass(
            $workflow['type'],
            "regression-corpus-{$id}",
            "regression-corpus-run-{$id}",
            $workflow['arguments'],
            $workflow['payload_codec'],
            $history,
        )->step();
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
}
