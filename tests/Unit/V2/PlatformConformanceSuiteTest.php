<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\SurfaceStabilityContract;

final class PlatformConformanceSuiteTest extends TestCase
{
    private const RUST_SIGNAL_QUERY_BINDING_PROVENANCE = [
        [
            29,
            '0.1.2',
            '009c0257964f33705941466d09777172068b3a26',
            '793c8e6f63310c51aa97380466a58bd68b4c90dc2277351a3bf7ba60be794cba',
        ],
        [
            30,
            '0.1.2',
            '3fa9bff54c8ccef5537a885b167e470a629661b9',
            '1807509b4a56463c37998e91e433ff7cf79c49c9eb9722d6f36fefb38ac615a0',
        ],
        [
            31,
            '2.0.0-beta.3',
            '4275fd11d80e88d4414383e4338144c228eb5a78',
            'eb79b471e654b14a517a077d526b085c56ac55b17405233df2ffbdf11e32e64b',
        ],
        [
            32,
            '2.0.0-beta.4',
            '187746bb19615a8cbb25dfbe1e4e27dbbd933472',
            '4acdcb70da1cebb77a44edb7cce68ef1d0315159d289c94af2f57f526c3cbbea',
        ],
        [
            33,
            '2.0.0-beta.5',
            '08fab5ff5c51fd31ce8306b39edb10996d5a8531',
            'd809264b6394935c0c4b3c30e6ba50252fba0c6743a81a2c747c39a28830277d',
        ],
        [
            34,
            '2.0.0-beta.6',
            '5191a97d90e3e476c4e6a51e90faa559868e4c70',
            'd067aa5e750a804d67fb501704a46394488309e33f6df461ba000b41530a87a0',
        ],
        [
            34,
            '2.0.0-beta.10',
            '0fddbec98b94a5b542480d746759a2c695bba2be',
            '6a90b3ba55b43feb332fb895a23045fdaf85b23357c1bbeff2e79321cd4afca8',
        ],
        [
            34,
            '2.0.0-beta.13',
            '0561e96950c68beabb3535a2f65f7403209885a5',
            'c2e567ba37e68354256e680a53b0890ede7e8f3b69d2ed9aede33ad8aa0af8a4',
        ],
        [
            34,
            '2.0.0-beta.14',
            'ef844b34dfec8cfe54d4bc699fc21d80574ce028',
            'ecc7c1b8427dd89fc370f7aafdd1a5d6089c8c60559f61af112d8d92e516dece',
        ],
        [
            34,
            '2.0.0-beta.16',
            '68262eb8589e8e1142c2e158f50815950a347ef8',
            '4f304b9d2dae9b3f71b800b49d22b7ae4c60fd69e37bece224ccca5818911222',
        ],
        [
            34,
            '2.0.0-beta.17',
            'f1ef7d4edd8b1cea28192bfe360d3a233721c0ca',
            '86cff1043fb2c97490b08a9fee0e6ca993eb2c3f4f03b863c61e4ffd5188cbaf',
        ],
        [
            34,
            '2.0.0-beta.18',
            '8853baf7d42e2bbdf08ed101dc0ba4e7bb0f4a31',
            '8285966e7ed1eb20942ea24bc008725e80e737a895ae43a0c69fdd13728531d5',
        ],
        [
            34,
            '2.0.0-beta.21',
            '636ff3fc90c1a01c8ee74becaa148c9e193969ea',
            'a60b114a3ad2285c4a9796d72de29919d3ba84e713f20fb0f6fa705aa957e525',
        ],
        [
            34,
            '2.0.0-rc.1',
            '864cd6f2e11a60ddbd221548019df8ef0cd8f812',
            'dba857beb24d0cd75adb7146d6e17b7728fb432e7d7e004a3a2553f630eb94cc',
        ],
        [
            34,
            '2.0.0-rc.2',
            '961bc3675b2c1c35577b66bccc77b2e4f4485369',
            '087764682d0f80e6f8f329baa0cab6adec1cfe3733083383dd1d2159cc607457',
        ],
        [
            34,
            '2.0.0-rc.1',
            '6c137c89f5b0efdfc5f5720ef81005dd67751aad',
            'dba857beb24d0cd75adb7146d6e17b7728fb432e7d7e004a3a2553f630eb94cc',
        ],
        [
            35,
            '2.0.0-rc.3',
            '2810085732928e0bae9a7ae16cc55149ad721635',
            'fa4664b9cc826c3573e131a7b88d62b1dbee761d2b1d0e93d3aacb6a5e64cb11',
        ],
        [
            36,
            '2.0.0-rc.4',
            'dd07456686d367c215e2637f586436b9710d7b35',
            'd256640d23b07c3d1a88e40854056074d8abd154360190712f67507c027ca8fc',
        ],
        [
            36,
            '2.0.0-rc.5',
            '257a758c4b20094246ec676f0340bb392cf867c6',
            '4679879192043c5aa725d8e9770719c41a2b991e5dfc565ca3e5c33670ffd0b9',
        ],
        [
            37,
            '2.0.0-rc.5',
            'e779bf19aeb78bdaeb020b23ac80b576ec125af8',
            '3469169d0df27e80386ceca489826200620d6dfc87f42e4deae0949da377652c',
        ],
    ];

    private const RUST_SIGNAL_QUERY_SCENARIOS = [
        'rust_worker_rust_php_python_clients',
        'python_worker_rust_client',
        'php_worker_rust_client',
        'rust_query_error_and_immutability',
        'rust_replayed_instance_state_query_after_cold_restart',
    ];

    public function testManifestExactlyMatchesCommittedPublicAuthorityFixture(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        $this->assertIsString($json);
        $this->assertSame(
            PlatformConformanceSuite::MIRROR_SHA256,
            hash('sha256', $json),
            'Changing any suite semantics requires a new reviewed authority digest.',
        );

        $authority = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($authority, PlatformConformanceSuite::manifest());
        $this->assertSame(37, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $authority['version']);
        $this->assertSame(PlatformConformanceSuite::SCHEMA, $authority['schema']);
        $this->assertSame(SurfaceStabilityContract::SCHEMA, $authority['surface_stability_authority']);
    }

    public function testTargetAndCategorySemanticsAreCompleteAndInternallyResolvable(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $categories = array_keys($manifest['fixture_catalog']);

        $this->assertSame(array_keys($manifest['targets']), PlatformConformanceSuite::targetNames());
        $this->assertSame($categories, PlatformConformanceSuite::fixtureCategoryNames());

        foreach ($manifest['targets'] as $targetName => $target) {
            foreach ($target['required_surface_families'] as $surfaceFamily) {
                $this->assertContains(
                    $surfaceFamily,
                    $surfaceFamilies,
                    "{$targetName} references an unknown surface family.",
                );
            }

            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertContains(
                    $category,
                    $categories,
                    "{$targetName} references an unknown fixture category.",
                );
            }
        }

        foreach ($manifest['fixture_catalog'] as $categoryName => $category) {
            $this->assertContains(
                $category['status'],
                [
                    PlatformConformanceSuite::CATEGORY_STATUS_STABLE,
                    PlatformConformanceSuite::CATEGORY_STATUS_PROVISIONAL,
                ],
                "{$categoryName} has an unknown stability status.",
            );
            $this->assertNotEmpty($category['sources'], "{$categoryName} must declare a source.");
        }
    }

    public function testPhpSdkAndEmbeddedWorkflowReleaseGatesAreIndependent(): void
    {
        $manifest = PlatformConformanceSuite::manifest();

        $this->assertSame(
            ['embedded_engine'],
            $manifest['release_gates']['gates']['durable-workflow/workflow']['required_targets'],
        );
        $this->assertSame(
            ['official_sdk', 'worker_protocol_implementation'],
            $manifest['release_gates']['gates']['durable-workflow/sdk']['required_targets'],
        );
        $this->assertSame(
            ['history_replay_bundles'],
            $manifest['targets']['embedded_engine']['required_fixture_categories'],
        );
    }

    public function testRustSignalQueryScenariosAreRequiredByStableTargetsAndPassFailRules(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];

        $this->assertSame(PlatformConformanceSuite::CATEGORY_STATUS_STABLE, $category['status']);

        foreach (self::RUST_SIGNAL_QUERY_SCENARIOS as $scenario) {
            $this->assertContains($scenario, $category['required_scenarios']);
        }

        foreach (['standalone_server', 'official_sdk', 'worker_protocol_implementation'] as $target) {
            $this->assertContains(
                'signal_query_runtime_contract',
                $manifest['targets'][$target]['required_fixture_categories'],
            );
        }

        $coverageRule = $manifest['pass_fail_rules']['stable_runtime_scenario_coverage'];
        $this->assertContains('signal_query_runtime_contract', $coverageRule['applies_to_categories']);
        $this->assertStringContainsString('every required scenario to pass', $coverageRule['rule']);
        $this->assertStringContainsString('runner-blocked cell is nonconforming', $coverageRule['rule']);
    }

    public function testRustSignalQueryScenarioContractsPreserveArtifactRolesAndImmutability(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $category = $manifest['fixture_catalog']['signal_query_runtime_contract'];
        $contracts = $category['required_scenario_contracts'];
        $artifact = [
            'package' => 'durable-workflow',
            'version' => '2.0.0-rc.5',
            'source' => 'crates.io',
            'cargo_requirement' => '=2.0.0-rc.5',
        ];

        $this->assertStringContainsString('Rust SDK', $manifest['targets']['official_sdk']['description']);
        $this->assertSame(self::RUST_SIGNAL_QUERY_SCENARIOS, array_keys($contracts));

        foreach ($contracts as $contract) {
            $this->assertSame($artifact, $contract['artifact']);
        }

        $this->assertSame('sdk-rust', $contracts['rust_worker_rust_php_python_clients']['worker_runtime']);
        $this->assertSame(
            ['sdk-rust', 'sdk-php', 'sdk-python'],
            $contracts['rust_worker_rust_php_python_clients']['caller_paths'],
        );
        $this->assertSame('sdk-python', $contracts['python_worker_rust_client']['worker_runtime']);
        $this->assertSame('sdk-php', $contracts['php_worker_rust_client']['worker_runtime']);
        $this->assertSame('client', $contracts['python_worker_rust_client']['rust_role']);
        $this->assertSame('client', $contracts['php_worker_rust_client']['rust_role']);

        $snapshot = $contracts['rust_query_error_and_immutability'];
        $this->assertSame('snapshot_derived_transport_state', $snapshot['query_state_model']);
        foreach ([
            'successful_query_emits_no_workflow_commands',
            'failed_query_emits_no_workflow_commands',
            'successful_query_appends_no_history',
            'failed_query_appends_no_history',
            'failed_query_does_not_change_later_answer',
        ] as $assertion) {
            $this->assertContains($assertion, $snapshot['required_assertions']);
        }

        $replay = $contracts['rust_replayed_instance_state_query_after_cold_restart'];
        $this->assertSame('replayed_workflow_instance_state', $replay['query_state_model']);
        $this->assertSame(
            [
                'start_running_workflow',
                'query_running_state',
                'cold_stop_rust_worker',
                'start_fresh_rust_worker_process',
                'restore_state_from_durable_history',
                'complete_restored_workflow',
                'query_completed_state',
            ],
            $replay['lifecycle'],
        );
        foreach ([
            'successful_replayed_query_emits_no_workflow_commands',
            'failed_replayed_query_emits_no_workflow_commands',
            'successful_replayed_query_appends_no_history',
            'failed_replayed_query_appends_no_history',
            'failed_replayed_query_does_not_change_state_returned_by_later_query',
        ] as $assertion) {
            $this->assertContains($assertion, $replay['required_assertions']);
        }
    }

    public function testRustSignalQueryArtifactHistoryIsImmutableAndCurrent(): void
    {
        $manifest = PlatformConformanceSuite::manifest();
        $history = $manifest['artifact_version_history']['rust_signal_query'];
        $bindings = $history['bindings'];

        $this->assertSame('observed_bindings_with_provenance', $history['history_mode']);
        $this->assertSame(37, $history['strict_suite_versioning_from']);

        $provenance = array_map(
            static fn (array $binding): array => [
                $binding['suite_version'],
                $binding['artifact']['version'],
                $binding['source_revision'],
                $binding['authority_sha256'],
            ],
            $bindings,
        );
        $knownBindingCount = count(self::RUST_SIGNAL_QUERY_BINDING_PROVENANCE);

        $this->assertGreaterThanOrEqual($knownBindingCount, count($bindings));
        $this->assertSame(
            self::RUST_SIGNAL_QUERY_BINDING_PROVENANCE,
            array_slice($provenance, 0, $knownBindingCount),
            'Recorded artifact bindings are append-only.',
        );

        $previousStrictBinding = null;
        foreach ($bindings as $binding) {
            $artifact = $binding['artifact'];

            $this->assertLessThanOrEqual($manifest['version'], $binding['suite_version']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $binding['source_revision']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $binding['authority_sha256']);
            $this->assertSame('durable-workflow', $artifact['package']);
            $this->assertSame('crates.io', $artifact['source']);
            $this->assertSame('=' . $artifact['version'], $artifact['cargo_requirement']);

            if ($binding['suite_version'] < $history['strict_suite_versioning_from']) {
                continue;
            }

            if ($previousStrictBinding !== null && $artifact !== $previousStrictBinding['artifact']) {
                $this->assertGreaterThan(
                    $previousStrictBinding['suite_version'],
                    $binding['suite_version'],
                    'Every exact artifact change must advance the suite version.',
                );
            }

            $previousStrictBinding = $binding;
        }

        $latestBinding = $bindings[array_key_last($bindings)];
        $this->assertLessThanOrEqual($manifest['version'], $latestBinding['suite_version']);

        $currentArtifact = $latestBinding['artifact'];
        $contracts = $manifest['fixture_catalog']['signal_query_runtime_contract']['required_scenario_contracts'];

        foreach ($contracts as $contract) {
            $this->assertSame($currentArtifact, $contract['artifact']);
        }
    }

    public function testWorkflowSourceReleaseAndQualifiedSdkTupleRemainExplicit(): void
    {
        $composerPath = dirname(__DIR__, 3) . '/composer.json';
        $composerJson = file_get_contents($composerPath);

        $this->assertIsString($composerJson);

        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        $workflowSourceRelease = $composer['extra']['durable-workflow']['product-train'];
        $surfaceManifest = SurfaceStabilityContract::manifest();
        $sdkCompatibility = $surfaceManifest['surface_families']['official_sdks']['package_compatibility'];
        $suiteManifest = PlatformConformanceSuite::manifest();
        $contracts = $suiteManifest['fixture_catalog']['signal_query_runtime_contract']['required_scenario_contracts'];

        $this->assertSame('2.0.0-rc.7', $workflowSourceRelease);

        foreach ($sdkCompatibility as $sdk) {
            $this->assertSame('2.0.0-rc.5', $sdk['release_line']);
            $this->assertSame('2.0.0-rc.5', $sdk['product_train']);
            $this->assertSame('2.0.0-rc.5', $sdk['supported_server_versions']);
            $this->assertNotSame($workflowSourceRelease, $sdk['release_line']);
        }

        $this->assertSame('2.0.0rc5', $sdkCompatibility['python_sdk']['registry_version']);

        foreach ($contracts as $contract) {
            $artifact = $contract['artifact'];

            $this->assertSame('durable-workflow', $artifact['package']);
            $this->assertSame('crates.io', $artifact['source']);
            $this->assertSame('2.0.0-rc.5', $artifact['version']);
            $this->assertSame('=' . $artifact['version'], $artifact['cargo_requirement']);
            $this->assertNotSame($workflowSourceRelease, $artifact['version']);
        }
    }

    public function testHistoricalReleaseGateCompatibilityNamesRemainDeclared(): void
    {
        $gates = PlatformConformanceSuite::manifest()['release_gates']['gates'];

        $this->assertArrayHasKey('durable-workflow/workflow', $gates);
        $this->assertArrayHasKey('durable-workflow/sdk', $gates);
        $this->assertArrayHasKey('durable_workflow', $gates);
        $this->assertSame(
            $gates['durable-workflow/sdk']['required_targets'],
            $gates['durable_workflow']['required_targets'],
        );
    }
}
