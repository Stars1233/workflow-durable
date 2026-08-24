<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class LaravelDependencySecurityPolicyTest extends TestCase
{
    public function testPolicyOwnsTheComposerAndCompatibilityConstraints(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $policyPath = $composer['extra']['durable-workflow']['laravel-dependency-security-policy'];
        $policy = json_decode(
            (string) file_get_contents($root . '/' . $policyPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $upgrade = json_decode(
            (string) file_get_contents($root . '/resources/laravel-embedded-upgrade-contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $constraint = implode('|', array_map(
            static fn (array $major): string => '^' . $major['minimum'],
            $policy['supported_majors'],
        ));

        $this->assertSame('durable-workflow.laravel-dependency-security-policy', $policy['schema']);
        $this->assertSame('laravel/framework', $policy['package']);
        $this->assertSame($constraint, $composer['require']['laravel/framework']);
        $this->assertSame($constraint, $upgrade['supported_intersection']['authority']['embedded_v2']['laravel']);
        $this->assertSame($policyPath, $upgrade['qualification']['dependency_security_policy']);
        $this->assertSame(
            array_map(static fn ($major): string => $major . '.*', array_keys($policy['supported_majors'])),
            array_keys($upgrade['supported_intersection']['authority']['laravel_minimum_php']),
        );
    }

    public function testDependabotDelegatesLaravelToThePerMajorMonitor(): void
    {
        $root = dirname(__DIR__, 3);
        $dependabot = Yaml::parseFile($root . '/.github/dependabot.yml');
        $composerUpdate = $dependabot['updates'][0];

        $this->assertSame('composer', $composerUpdate['package-ecosystem']);
        $this->assertSame('/', $composerUpdate['directory']);
        $this->assertArrayNotHasKey('target-branch', $composerUpdate);
        $this->assertSame(0, $composerUpdate['open-pull-requests-limit']);
        $this->assertContains([
            'dependency-name' => 'laravel/framework',
        ], $composerUpdate['ignore'],);

        $workflow = Yaml::parseFile($root . '/.github/workflows/dependency-security.yml');
        $steps = $workflow['jobs']['laravel-supported-majors']['steps'];
        $commands = array_column($steps, 'run');

        $this->assertContains('php scripts/ci/check-laravel-dependency-security.php --policy-only', $commands);
        $this->assertContains('php scripts/ci/check-laravel-dependency-security.php', $commands);
    }

    public function testPolicyOnlyMonitorValidationPassesWithoutAConsumerLockfile(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            PHP_BINARY,
            $root . '/scripts/ci/check-laravel-dependency-security.php',
            '--policy-only',
        ]);
        $process->mustRun();

        $this->assertStringContainsString('internally consistent', $process->getOutput());
    }
}
