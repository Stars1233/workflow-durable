<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SurfaceStabilityContract;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Pins the packaged consumer-facing protocol catalog. The same JSON document
 * is published at https://durable-workflow.github.io/platform-protocol-specs.json
 * and re-exported by the standalone server under `platform_protocol_specs`.
 */
final class PlatformProtocolSpecsTest extends TestCase
{
    public function testPackagedCatalogIsTheDigestedRuntimeAuthority(): void
    {
        $path = dirname(__DIR__, 3) . '/' . PlatformProtocolSpecs::PACKAGE_CONTRACT_PATH;
        $json = file_get_contents($path);

        $this->assertIsString($json);
        $this->assertSame(PlatformProtocolSpecs::MIRROR_SHA256, hash('sha256', $json));
        $this->assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), PlatformProtocolSpecs::manifest());
    }

    public function testManifestAdvertisesAuthorityIdentity(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(PlatformProtocolSpecs::SCHEMA, $manifest['schema']);
        $this->assertSame(16, $manifest['version']);
        $this->assertSame(PlatformProtocolSpecs::CATALOG_URL, $manifest['catalog_url']);
        $this->assertSame(PlatformProtocolSpecs::AUTHORITY_URL, $manifest['authority_url']);
    }

    public function testManifestEnumeratesFormatsOpenApiJsonSchemaAsyncApi(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(['openapi', 'json_schema', 'asyncapi'], array_keys($manifest['formats']));

        foreach ($manifest['formats'] as $format => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "format {$format} needs meaning");
            $this->assertArrayHasKey('file_extensions', $definition, "format {$format} needs file_extensions");
        }
    }

    public function testStatusLevelsCoverPublishedInProgressPlanned(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(['published', 'in_progress', 'planned'], array_keys($manifest['status_levels']));

        foreach ($manifest['status_levels'] as $level => $meaning) {
            $this->assertIsString($meaning, "status level {$level} meaning must be a string");
            $this->assertNotSame('', $meaning, "status level {$level} must have a non-empty meaning");
        }
    }

    public function testEvolutionRulesNameAdditiveParallelExperimental(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();

        $this->assertSame(
            ['additive_minor_breaking_major', 'parallel_primitive_only', 'experimental_any_release'],
            array_keys($manifest['evolution_rules']),
        );

        foreach ($manifest['evolution_rules'] as $rule => $definition) {
            $this->assertArrayHasKey('meaning', $definition, "evolution rule {$rule} needs meaning");
            $this->assertArrayHasKey(
                'applies_to_formats',
                $definition,
                "evolution rule {$rule} needs applies_to_formats",
            );
            foreach ($definition['applies_to_formats'] as $format) {
                $this->assertContains(
                    $format,
                    PlatformProtocolSpecs::formatValues(),
                    "evolution rule {$rule} applies_to_formats must use the format vocabulary",
                );
            }
        }

        $this->assertSame(
            ['json_schema'],
            $manifest['evolution_rules']['parallel_primitive_only']['applies_to_formats'],
        );
    }

    public function testCatalogCoversTheDeliverableSurfaceSet(): void
    {
        $this->assertSame(
            [
                'control_plane_api',
                'worker_protocol_api',
                'worker_protocol_stream',
                'worker_sessions_runtime',
                'local_activity_runtime',
                'history_event_payloads',
                'history_export_bundle',
                'replay_bundle',
                'waterline_read_api',
                'waterline_diagnostic_objects',
                'repair_actionability_objects',
                'cli_json_envelopes',
                'mcp_discovery',
                'mcp_tool_results',
                'cluster_info_envelope',
                'invocable_carrier_execution',
            ],
            PlatformProtocolSpecs::specNames(),
        );
    }

    public function testEverySpecEntryHasAConsumablePublicReference(): void
    {
        $manifest = PlatformProtocolSpecs::manifest();
        $allowedFormats = PlatformProtocolSpecs::formatValues();
        $allowedStatuses = PlatformProtocolSpecs::statusValues();
        $allowedOwners = PlatformProtocolSpecs::ownerRepoValues();
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $requiredFields = [
            'description',
            'format',
            'spec_id',
            'surface_family',
            'authority_manifest',
            'owner_repo',
            'object_families',
            'evolution_rule',
            'breaking_change_release',
            'status',
        ];

        foreach ($manifest['specs'] as $name => $spec) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $spec, "spec {$name} is missing {$field}");
            }

            $this->assertContains($spec['format'], $allowedFormats);
            $this->assertContains($spec['status'], $allowedStatuses);
            $this->assertContains($spec['owner_repo'], $allowedOwners);
            $this->assertContains($spec['surface_family'], $surfaceFamilies);
            $this->assertStringStartsWith('durable-workflow.v2.', $spec['spec_id']);
            if ($spec['status'] === 'planned') {
                $this->assertArrayNotHasKey('spec_url', $spec);
            } else {
                $this->assertArrayHasKey('spec_url', $spec);
                $this->assertStringStartsWith(
                    'https://durable-workflow.github.io/platform-protocol-specs/',
                    $spec['spec_url'],
                    "spec {$name} must expose a public HTTPS reference",
                );
                $this->assertSame('https', parse_url($spec['spec_url'], PHP_URL_SCHEME));
                $this->assertSame('durable-workflow.github.io', parse_url($spec['spec_url'], PHP_URL_HOST));
            }

            $this->assertNotEmpty($spec['object_families']);
            foreach ($spec['object_families'] as $family) {
                $this->assertSame(['name', 'owner_repo'], array_keys($family));
                $this->assertContains($family['owner_repo'], $allowedOwners);
            }
        }
    }

    public function testCatalogDoesNotExposeRepositoryLocalAuthority(): void
    {
        $json = json_encode(PlatformProtocolSpecs::manifest(), JSON_THROW_ON_ERROR);

        foreach ([
            '"spec_path"',
            '"owner_symbol"',
            '"conformance_test"',
            '"schema_authority"',
            '"version_authority"',
            'tests/',
            'scripts/',
            'static/',
            '::',
            '\\\\',
        ] as $repositoryLocalReference) {
            $this->assertStringNotContainsString(
                $repositoryLocalReference,
                $json,
                "public catalog must not expose {$repositoryLocalReference}",
            );
        }
    }

    public function testWorkerProtocolApiCatalogCoversQueryTasks(): void
    {
        $entry = PlatformProtocolSpecs::manifest()['specs']['worker_protocol_api'];

        $this->assertStringContainsString('query tasks', $entry['description']);
        $this->assertStringContainsString('query_tasks', $entry['description']);
        $families = array_column($entry['object_families'], 'name');
        $this->assertContains('worker_query_task_poll_request', $families);
        $this->assertContains('worker_query_task_result', $families);
    }

    public function testWorkerDeregistrationOpenApiMatchesCatalogAndImmutableMirror(): void
    {
        $root = dirname(__DIR__, 3);
        $workerSpecPath = $root
            . '/resources/conformance/suite-v44/platform-protocol-specs/worker-protocol-api.openapi.yaml';
        $workerSpec = Yaml::parseFile($workerSpecPath);

        $this->assertIsArray($workerSpec);
        $this->assertSame('12', $workerSpec['info']['version']);
        $this->assertSame(16, $workerSpec['x-durable-workflow-catalog-version']);

        $route = $workerSpec['paths']['/worker/registrations/{workerId}'];
        $this->assertSame(['delete'], array_keys($route));

        $operation = $route['delete'];
        $this->assertSame('deregisterWorker', $operation['operationId']);
        $this->assertSame(['worker-lifecycle'], $operation['tags']);
        $this->assertSame('worker', $operation['x-durable-workflow-required-role']);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame(
            ['#/components/parameters/WorkerProtocolVersionHeader', '#/components/parameters/WorkerIdPath'],
            array_column($operation['parameters'], '$ref'),
        );
        $this->assertSame(
            ['200', '400', '401', '403', '404', '409'],
            array_map('strval', array_keys($operation['responses'])),
        );
        $this->assertSame(
            '#/components/responses/WorkerDeregistrationEnvelope',
            $operation['responses']['200']['$ref'],
        );
        foreach (['400', '401', '403', '404', '409'] as $status) {
            $this->assertSame('#/components/responses/WorkerError', $operation['responses'][$status]['$ref']);
        }

        $this->assertSame(
            [
                'name' => 'workerId',
                'in' => 'path',
                'required' => true,
                'description' => 'Worker registration identity in the resolved namespace.',
                'schema' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
            ],
            $workerSpec['components']['parameters']['WorkerIdPath'],
        );
        $this->assertSame(
            [
                [
                    '$ref' => '#/components/schemas/WorkerEnvelope',
                ],
                [
                    '$ref' => '#/components/schemas/WorkerDeregistrationResult',
                ],
            ],
            $workerSpec['components']['responses']['WorkerDeregistrationEnvelope']['content']['application/json']['schema']['allOf'],
        );
        $this->assertSame(
            ['worker_id', 'outcome', 'recovered_workflow_task_count'],
            $workerSpec['components']['schemas']['WorkerDeregistrationResult']['required'],
        );
        $this->assertSame(
            'deregistered',
            $workerSpec['components']['schemas']['WorkerDeregistrationResult']['properties']['outcome']['const'],
        );

        $catalogFamilies = PlatformProtocolSpecs::manifest()['specs']['worker_protocol_api']['object_families'];
        $this->assertContains('worker_deregistration_result', array_column($catalogFamilies, 'name'));
        $this->assertSame($catalogFamilies, $workerSpec['x-durable-workflow-object-families']);

        $workerSources = PlatformConformanceSuite::manifest()['fixture_catalog']['worker_task_lifecycle']['sources'];
        $workerApiSources = array_values(array_filter(
            $workerSources,
            static fn (array $source): bool => str_contains($source['artifact_id'], 'worker-protocol-api'),
        ));
        $this->assertCount(1, $workerApiSources);
        $this->assertSame('durable-workflow.v2.worker-protocol-api@catalog-16', $workerApiSources[0]['artifact_id']);
        $this->assertSame(
            'https://durable-workflow.github.io/platform-protocol-specs/v1.16/'
                . 'worker-protocol-api.openapi.yaml',
            $workerApiSources[0]['resolver_url'],
        );
        $this->assertSame('sha256:' . hash_file('sha256', $workerSpecPath), $workerApiSources[0]['sha256']);

        $controlPlaneSpec = Yaml::parseFile(
            $root . '/resources/conformance/suite-v38/platform-protocol-specs/control-plane-api.openapi.yaml',
        );
        $this->assertIsArray($controlPlaneSpec);
        $this->assertSame('deleteWorker', $controlPlaneSpec['paths']['/workers/{workerId}']['delete']['operationId']);
        $this->assertArrayNotHasKey('/workers/{workerId}', $workerSpec['paths']);
    }

    public function testCurrentWorkerProtocolAddsTypedSearchAttributesWithoutChangingHistoricalBundles(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/conformance';
        $beta = Yaml::parseFile($root . '/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml');
        $protocol113 = Yaml::parseFile(
            $root . '/suite-v41/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        );
        $protocol115 = Yaml::parseFile(
            $root . '/suite-v43/platform-protocol-specs/worker-protocol-api.openapi.yaml',
        );
        $current = Yaml::parseFile($root . '/suite-v44/platform-protocol-specs/worker-protocol-api.openapi.yaml');

        $this->assertIsArray($beta);
        $this->assertIsArray($protocol113);
        $this->assertIsArray($protocol115);
        $this->assertIsArray($current);
        $this->assertSame('1.13', $protocol113['components']['schemas']['AdvertisedWorkerProtocolVersion']['const']);
        $this->assertSame(
            $this->withoutDescriptionMetadata($beta),
            $this->withoutDescriptionMetadata($protocol113),
            'The retained lifecycle correction must not change OpenAPI wire structure or behavior.',
        );
        $this->assertNotSame(
            hash_file('sha256', $root . '/suite-v38/platform-protocol-specs/worker-protocol-api.openapi.yaml'),
            hash_file('sha256', $root . '/suite-v41/platform-protocol-specs/worker-protocol-api.openapi.yaml'),
        );
        $this->assertSame('1.15', $protocol115['components']['schemas']['AdvertisedWorkerProtocolVersion']['const']);
        $this->assertSame('1.16', $current['components']['schemas']['AdvertisedWorkerProtocolVersion']['const']);
        $this->assertArrayHasKey(
            'message_stream_cursors',
            $current['components']['schemas']['WorkflowTaskCompleteRequest']['properties'],
        );
        $this->assertArrayHasKey(
            'message_stream_waits',
            $current['components']['schemas']['WorkflowTaskCompleteRequest']['properties'],
        );
        $this->assertArrayNotHasKey(
            'message_stream_cursors',
            $protocol113['components']['schemas']['WorkflowTaskCompleteRequest']['properties'],
        );
        $this->assertArrayNotHasKey(
            'message_stream_waits',
            $protocol113['components']['schemas']['WorkflowTaskCompleteRequest']['properties'],
        );
        $this->assertArrayNotHasKey('x-durable-workflow-typed-search-attributes-contract', $protocol115);
        $this->assertArrayHasKey('x-durable-workflow-typed-search-attributes-contract', $current);
        $typedCommand = $current['components']['schemas']['WorkflowCommand']['allOf'][0]['then']['properties'];
        $this->assertArrayHasKey('attribute_types', $typedCommand);
        $this->assertSame('1.16', $typedCommand['attribute_types']['x-durable-workflow-minimum-protocol-version']);
    }

    public function testRuntimeAndCurrentWorkerSpecsCannotDriftOnMessageStreamContract(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/conformance/suite-v44/platform-protocol-specs';
        $openApi = Yaml::parseFile($root . '/worker-protocol-api.openapi.yaml');
        $asyncApi = Yaml::parseFile($root . '/worker-protocol-stream.asyncapi.yaml');

        $this->assertIsArray($openApi);
        $this->assertIsArray($asyncApi);

        $runtime = WorkerProtocolVersion::describe();
        $negotiation = SurfaceStabilityContract::manifest()['surface_families']['worker_protocol']['negotiation'];
        $expectedVersions = array_map(static fn (int $minor): string => "1.{$minor}", range(0, 16));

        foreach ([$openApi, $asyncApi] as $spec) {
            $this->assertSame(
                WorkerProtocolVersion::VERSION,
                $spec['x-durable-workflow-worker-protocol-negotiation']['default_advertised_version'],
            );
            $this->assertSame(
                $expectedVersions,
                $spec['x-durable-workflow-worker-protocol-negotiation']['accepted_request_versions_by_default'],
            );
            $this->assertSame(
                $runtime['message_streams']['minimum_protocol_version'],
                $spec['x-durable-workflow-message-streams-contract']['minimum_protocol_version'],
            );
            $this->assertSame(
                $runtime['message_streams']['worker_capability'],
                $spec['x-durable-workflow-message-streams-contract']['worker_capability'],
            );
            $this->assertSame(
                array_keys($runtime['message_streams']['completion_fields']),
                $spec['x-durable-workflow-message-streams-contract']['completion_fields'],
            );
            $this->assertSame(
                $runtime['message_streams']['version_gate']['rejection_status'],
                $spec['x-durable-workflow-message-streams-contract']['version_gate']['rejection_status'],
            );
            $this->assertSame(
                $runtime['message_streams']['version_gate']['rejection_reason'],
                $spec['x-durable-workflow-message-streams-contract']['version_gate']['rejection_reason'],
            );
            $this->assertSame(
                $runtime['upsert_search_attributes_command']['attribute_types']['minimum_protocol_version'],
                $spec['x-durable-workflow-typed-search-attributes-contract']['minimum_protocol_version'],
            );
            $this->assertSame(
                $runtime['upsert_search_attributes_command']['history']['replay_identity'],
                $spec['x-durable-workflow-typed-search-attributes-contract']['history']['replay_identity'],
            );
        }

        $this->assertSame(WorkerProtocolVersion::VERSION, $negotiation['default_advertised_version']);
        $this->assertSame($expectedVersions, $negotiation['accepted_request_versions_by_default']);
        $this->assertSame(
            WorkerProtocolVersion::VERSION,
            $openApi['components']['schemas']['AdvertisedWorkerProtocolVersion']['const'],
        );
        $this->assertSame(
            WorkerProtocolVersion::VERSION,
            $asyncApi['components']['schemas']['ProtocolEnvelope']['properties']['protocol_version']['const'],
        );

        $openApiCompletion = $openApi['components']['schemas']['WorkflowTaskCompleteRequest']['properties'];
        $asyncApiCompletion = $asyncApi['components']['schemas']['WorkflowTaskCompletionEvent']['allOf'][1]['properties'];
        foreach ($runtime['message_streams']['completion_fields'] as $field => $shape) {
            foreach ([$openApiCompletion[$field], $asyncApiCompletion[$field]] as $fieldSchema) {
                $this->assertSame($shape['max_items'], $fieldSchema['maxItems']);
                $this->assertSame(
                    $runtime['message_streams']['minimum_protocol_version'],
                    $fieldSchema['x-durable-workflow-minimum-protocol-version'],
                );
            }

            $itemSchemaName = $field === 'message_stream_cursors'
                ? 'MessageStreamCursorAdvance'
                : 'MessageStreamWait';
            $positionField = $field === 'message_stream_cursors' ? 'through_position' : 'after_position';
            foreach ([$openApi, $asyncApi] as $spec) {
                $itemSchema = $spec['components']['schemas'][$itemSchemaName];
                $this->assertSame($shape['item']['additional_fields_allowed'], $itemSchema['additionalProperties']);
                $this->assertSame($shape['item']['required_fields'], $itemSchema['required']);
                $this->assertSame(
                    $shape['item']['stream_name']['pattern'],
                    $itemSchema['properties']['stream_name']['pattern'],
                );
                $this->assertSame(
                    $shape['item'][$positionField]['minimum'],
                    $itemSchema['properties'][$positionField]['minimum'],
                );
            }
        }

        $this->assertSame(
            $runtime['message_streams']['minimum_protocol_version'],
            $openApi['components']['schemas']['WorkerRegistrationRequest']['properties']['capabilities']['x-durable-workflow-version-gated-values'][WorkerProtocolVersion::CAPABILITY_MESSAGE_STREAMS],
        );
        $this->assertSame(
            WorkerProtocolVersion::VERSION,
            $openApi['components']['schemas']['WorkerRegistrationRequest']['properties']['capabilities']['x-durable-workflow-version-gated-values'][WorkerProtocolVersion::CAPABILITY_TYPED_SEARCH_ATTRIBUTES],
        );
    }

    public function testFrozenBundlesUseTheParallelPrimitiveRule(): void
    {
        foreach (['history_event_payloads', 'history_export_bundle'] as $name) {
            $entry = PlatformProtocolSpecs::manifest()['specs'][$name];
            $this->assertSame('parallel_primitive_only', $entry['evolution_rule']);
            $this->assertSame('parallel_primitive_only', $entry['breaking_change_release']);
        }
    }

    public function testReleaseCheckDescribesTheMachineChecksThatRun(): void
    {
        $check = PlatformProtocolSpecs::manifest()['release_check'];

        foreach ([
            'catalog_aligned_with_surface_families',
            'owner_repo_known',
            'format_known',
            'public_spec_references_resolve',
            'repository_local_authority_fields_rejected',
            'workflow_package_mirror_aligned',
            'server_owned_spec_mirrors_aligned',
            'diagnostic_provenance_complete',
            'object_family_metadata_declared',
            'breaking_change_release_consistent_with_evolution_rule',
            'deliverable_specs_published',
        ] as $gate) {
            $this->assertArrayHasKey($gate, $check['gates']);
        }

        $this->assertArrayNotHasKey('docs_authority_aligned', $check['gates']);
        $this->assertStringNotContainsString('docs/platform-protocol-specs.md', $check['enforcement']['machine']);
        $this->assertStringNotContainsString('walks', $check['enforcement']['machine']);
    }

    public function testOwnerRepoVocabularyMatchesTheFleet(): void
    {
        $this->assertSame(
            [
                'durable-workflow/workflow',
                'durable-workflow/server',
                'durable-workflow/waterline',
                'durable-workflow/durable-workflow.github.io',
                'durable-workflow/cli',
                'durable-workflow/sdk-python',
            ],
            PlatformProtocolSpecs::ownerRepoValues(),
        );
    }

    public function testCatalogReferencesOnlyDeclaredSurfaceFamilies(): void
    {
        $surfaceFamilies = array_keys(SurfaceStabilityContract::manifest()['surface_families']);
        $referenced = [];
        foreach (PlatformProtocolSpecs::manifest()['specs'] as $spec) {
            $referenced[$spec['surface_family']] = true;
        }

        foreach (array_keys($referenced) as $family) {
            $this->assertContains($family, $surfaceFamilies);
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function withoutDescriptionMetadata(array $node): array
    {
        unset($node['description']);

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->withoutDescriptionMetadata($value);
            }
        }

        return $node;
    }
}
