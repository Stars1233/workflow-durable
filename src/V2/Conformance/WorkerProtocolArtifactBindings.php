<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;
use Workflow\V2\Support\PlatformConformanceSuite;

final class WorkerProtocolArtifactBindings
{
    private const RETAINED_API_BETA_PATH =
        'resources/conformance/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_API_PROTOCOL_113_PATH =
        'resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    private const RETAINED_STREAM_PROTOCOL_113_PATH =
        'resources/conformance/suite-v41/platform-protocol-specs/worker-protocol-stream.asyncapi.yaml';

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function assertManifest(array $manifest): void
    {
        $historicalBetaResolver = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/e990bc36731463cc5b2cb2a9175dbccfdea61704/'
            . 'static/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $historicalProtocol113Base = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/' . PlatformConformanceSuite::PROTOCOL_SOURCE_REVISION
            . '/static/platform-protocol-specs/';
        $currentBase = 'https://durable-workflow.github.io/platform-protocol-specs/v1.15/';

        $expectedApi = [
            'history_mode' => 'immutable_lifecycle_bindings',
            'bindings' => [
                [
                    'suite_version' => 40,
                    'status' => 'historical',
                    'lifecycle' => 'beta',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-beta-history',
                    'resolver_url' => $historicalBetaResolver,
                    'sha256' => 'sha256:3166ce8ecb4c15005f1d98b1669f1ffaf3aeff7e19d0f006454525b2b19e4035',
                ],
                [
                    'suite_version' => 41,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-protocol-1.13-history',
                    'resolver_url' => $historicalProtocol113Base . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:55dfede6a9742f955911786eeb588ceecaa4266cebad57b92684c2a1bacefe7b',
                ],
                [
                    'suite_version' => PlatformConformanceSuite::VERSION,
                    'status' => 'current',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16',
                    'resolver_url' => $currentBase . 'worker-protocol-api.openapi.yaml',
                    'sha256' => 'sha256:d21a59e98ef46419b0792e716bd359c424a5759140474b838b1398083a291df6',
                ],
            ],
        ];
        $expectedStream = [
            'history_mode' => 'immutable_lifecycle_bindings',
            'bindings' => [
                [
                    'suite_version' => 41,
                    'status' => 'historical',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16-protocol-1.13-history',
                    'resolver_url' => $historicalProtocol113Base . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:15bddb75d0e7183a520e861f87d5315b65e42acdc57a8137f947e00cacbac251',
                ],
                [
                    'suite_version' => PlatformConformanceSuite::VERSION,
                    'status' => 'current',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-stream@catalog-16',
                    'resolver_url' => $currentBase . 'worker-protocol-stream.asyncapi.yaml',
                    'sha256' => 'sha256:388fd30483c0bb52c6b39cee219be3c9fc933ff815ccf4a06f9063c85902b458',
                ],
            ],
        ];

        if (
            ($manifest['artifact_version_history']['worker_protocol_api'] ?? null) !== $expectedApi
            || ($manifest['artifact_version_history']['worker_protocol_stream'] ?? null) !== $expectedStream
        ) {
            throw new RuntimeException(
                'Worker protocol artifact history must retain prior bytes and identify the current 1.15 authority.'
            );
        }

        self::assertRetainedBinding(self::RETAINED_API_BETA_PATH, $expectedApi['bindings'][0]['sha256']);
        self::assertRetainedBinding(self::RETAINED_API_PROTOCOL_113_PATH, $expectedApi['bindings'][1]['sha256']);
        self::assertRetainedBinding(
            self::RETAINED_STREAM_PROTOCOL_113_PATH,
            $expectedStream['bindings'][0]['sha256'],
        );

        $activeSources = $manifest['fixture_catalog']['worker_task_lifecycle']['sources'] ?? [];
        self::assertActiveBinding($activeSources, $expectedApi['bindings'][2]);
        self::assertActiveBinding($activeSources, $expectedStream['bindings'][1]);
    }

    private static function assertRetainedBinding(string $path, string $expectedDigest): void
    {
        $digest = hash_file('sha256', dirname(__DIR__, 3) . '/' . $path);
        if ($digest === false || ! hash_equals($expectedDigest, 'sha256:' . $digest)) {
            throw new RuntimeException('Historical worker protocol authority does not match its retained bytes.');
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private static function assertActiveBinding(mixed $sources, array $binding): void
    {
        $active = array_values(array_filter(
            is_array($sources) ? $sources : [],
            static fn (mixed $source): bool => is_array($source)
                && ($source['artifact_id'] ?? null) === $binding['artifact_id'],
        ));
        unset($binding['suite_version'], $binding['status'], $binding['lifecycle']);

        if (count($active) !== 1 || $active[0] !== $binding) {
            throw new RuntimeException('Active worker protocol source must match its current retained binding.');
        }
    }
}
