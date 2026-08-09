<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;
use Workflow\V2\Support\PlatformConformanceSuite;

final class WorkerProtocolArtifactBindings
{
    private const RETAINED_SPEC_PATH =
        'resources/conformance/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml';

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function assertManifest(array $manifest): void
    {
        $history = $manifest['artifact_version_history']['worker_protocol_api'] ?? null;
        $historicalResolver = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/e990bc36731463cc5b2cb2a9175dbccfdea61704/'
            . 'static/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $currentResolver = 'https://raw.githubusercontent.com/durable-workflow/'
            . 'durable-workflow.github.io/' . PlatformConformanceSuite::PROTOCOL_SOURCE_REVISION
            . '/static/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $expected = [
            'history_mode' => 'immutable_lifecycle_bindings',
            'bindings' => [
                [
                    'suite_version' => 40,
                    'status' => 'historical',
                    'lifecycle' => 'beta',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16-beta-history',
                    'resolver_url' => $historicalResolver,
                    'sha256' => 'sha256:3166ce8ecb4c15005f1d98b1669f1ffaf3aeff7e19d0f006454525b2b19e4035',
                ],
                [
                    'suite_version' => PlatformConformanceSuite::VERSION,
                    'status' => 'current',
                    'lifecycle' => 'lifecycle_neutral',
                    'artifact_id' => 'durable-workflow.v2.worker-protocol-api@catalog-16',
                    'resolver_url' => $currentResolver,
                    'sha256' => 'sha256:55dfede6a9742f955911786eeb588ceecaa4266cebad57b92684c2a1bacefe7b',
                ],
            ],
        ];

        if ($history !== $expected) {
            throw new RuntimeException(
                'Worker protocol artifact history must distinguish the historical beta bytes from current authority.'
            );
        }

        $historicalPath = dirname(__DIR__, 3) . '/' . self::RETAINED_SPEC_PATH;
        $historicalDigest = hash_file('sha256', $historicalPath);
        if ($historicalDigest === false || ! hash_equals(
            $expected['bindings'][0]['sha256'],
            'sha256:' . $historicalDigest
        )) {
            throw new RuntimeException(
                'Historical worker protocol authority does not match its retained byte binding.'
            );
        }

        $activeSources = $manifest['fixture_catalog']['worker_task_lifecycle']['sources'] ?? [];
        $activeWorkerSource = array_values(array_filter(
            is_array($activeSources) ? $activeSources : [],
            static fn (mixed $source): bool => is_array($source)
                && ($source['artifact_id'] ?? null) === 'durable-workflow.v2.worker-protocol-api@catalog-16',
        ));
        $currentBinding = $expected['bindings'][1];
        unset($currentBinding['suite_version'], $currentBinding['status'], $currentBinding['lifecycle']);
        if (count($activeWorkerSource) !== 1 || $activeWorkerSource[0] !== $currentBinding) {
            throw new RuntimeException(
                'Active worker protocol source must match the current lifecycle-neutral binding.'
            );
        }
    }
}
