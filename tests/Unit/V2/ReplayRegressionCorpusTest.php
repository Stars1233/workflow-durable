<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;

final class ReplayRegressionCorpusTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/ReplayRegression';

    private const FIXTURE_SCHEMA = 'durable-workflow.replay-regression/v1';

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
            self::assertIsArray($fixture['expected'] ?? null);

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
