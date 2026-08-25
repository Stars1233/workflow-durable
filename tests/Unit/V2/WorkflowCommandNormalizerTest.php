<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class WorkflowCommandNormalizerTest extends NonDatabaseTestCase
{
    public function testPayloadEnvelopeFieldContractNamesCodecBearingCommandPayloads(): void
    {
        $this->assertSame([
            'complete_workflow' => ['result'],
            'schedule_activity' => ['arguments'],
            'start_child_workflow' => ['arguments'],
            'continue_as_new' => ['arguments'],
            'complete_update' => ['result'],
            'record_side_effect' => ['result'],
            'start_service_operation' => ['request_payload'],
            'upsert_memo' => ['entries'],
        ], WorkflowCommandNormalizer::payloadEnvelopeFields());

        $this->assertTrue(WorkflowCommandNormalizer::acceptsPayloadEnvelope('complete_update', 'result'));
        $this->assertTrue(WorkflowCommandNormalizer::acceptsPayloadEnvelope('record_side_effect', 'result'));
        $this->assertTrue(
            WorkflowCommandNormalizer::acceptsPayloadEnvelope('start_service_operation', 'request_payload')
        );
        $this->assertTrue(WorkflowCommandNormalizer::acceptsPayloadEnvelope('upsert_memo', 'entries'));
        $this->assertFalse(WorkflowCommandNormalizer::acceptsPayloadEnvelope('complete_update', 'arguments'));
        $this->assertFalse(WorkflowCommandNormalizer::acceptsPayloadEnvelope('fail_update', 'result'));
    }

    public function testCompleteWorkflowAcceptsRawStringResult(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => '"ok"',
        ]], $out);
    }

    public function testCompleteWorkflowRejectsNonStringResultPayload(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'complete_workflow',
                'result' => [
                    'ok' => true,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.result', $errors);
        $this->assertStringContainsString(
            'must be a string or a payload envelope',
            $errors['commands.0.result'][0],
        );
    }

    public function testCompleteWorkflowUnwrapsEnvelope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', 'ok'),
                ],
            ],
        ]);

        $blob = Serializer::serializeWithCodec('avro', 'ok');

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => $blob,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testCompleteWorkflowPreservesEnvelopeCodec(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [
            'ok' => true,
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => [
                    'codec' => 'avro',
                    'blob' => $blob,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => $blob,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testCompleteWorkflowRejectsJsonEnvelope(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => [
                    'codec' => 'json',
                    'blob' => '{"stale":true}',
                ],
            ],
        ]);
    }

    public function testFailWorkflowRequiresNonEmptyMessage(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => '   ',
            ],
        ]);
    }

    public function testFailWorkflowPreservesOptionalFields(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'exception_class' => 'App\\Exceptions\\Boom',
                'exception_type' => 'typed_boom',
                'exception' => [
                    'class' => 'App\\Exceptions\\Boom',
                    'type' => 'typed_boom',
                    'message' => 'typed boom detail',
                ],
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame([[
            'type' => 'fail_workflow',
            'message' => 'boom',
            'exception_class' => 'App\\Exceptions\\Boom',
            'exception_type' => 'typed_boom',
            'exception' => [
                'class' => 'App\\Exceptions\\Boom',
                'type' => 'typed_boom',
                'message' => 'typed boom detail',
            ],
            'non_retryable' => true,
        ]], $out);
    }

    public function testScheduleActivityRequiresActivityType(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => '',
            ],
        ]);
    }

    public function testScheduleActivityTrimsFieldsAndResolvesArgumentsEnvelope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => '  SendEmail ',
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', ['hi']),
                ],
                'connection' => ' redis ',
                'queue' => 'default',
            ],
        ]);

        $arguments = Serializer::serializeWithCodec('avro', ['hi']);

        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'SendEmail',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
            'connection' => 'redis',
            'queue' => 'default',
        ]], $out);
    }

    public function testScheduleActivityPreservesArgumentsEnvelopeCodec(): void
    {
        $arguments = Serializer::serializeWithCodec('avro', ['hi']);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => $arguments,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'SendEmail',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testScheduleActivityPreservesRetryPolicyAndTimeouts(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'retry_policy' => [
                    'max_attempts' => 4,
                    'backoff_seconds' => [1, 5, 30],
                    'non_retryable_error_types' => ['ValidationError', 'PaymentDeclined'],
                ],
                'start_to_close_timeout' => 120,
                'schedule_to_start_timeout' => 10,
                'schedule_to_close_timeout' => 300,
                'heartbeat_timeout' => 15,
            ],
        ]);

        $this->assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'SendEmail',
            'retry_policy' => [
                'max_attempts' => 4,
                'backoff_seconds' => [1, 5, 30],
                'non_retryable_error_types' => ['ValidationError', 'PaymentDeclined'],
            ],
            'start_to_close_timeout' => 120,
            'schedule_to_start_timeout' => 10,
            'schedule_to_close_timeout' => 300,
            'heartbeat_timeout' => 15,
        ]], $out);
    }

    public function testScheduleActivityRejectsInvalidRetryPolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'retry_policy' => [
                    'max_attempts' => 0,
                    'backoff_seconds' => [1, -1],
                ],
            ],
        ]);
    }

    public function testStartTimerRequiresNonNegativeDelay(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_timer',
                'delay_seconds' => -1,
            ],
        ]);
    }

    public function testStartTimerPassesThrough(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_timer',
                'delay_seconds' => 30,
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_timer',
            'delay_seconds' => 30,
        ]], $out);
    }

    public function testStartChildWorkflowValidatesParentClosePolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'parent_close_policy' => 'orphan',
            ],
        ]);
    }

    public function testStartChildWorkflowAcceptsKnownPolicy(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'parent_close_policy' => 'abandon',
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_child_workflow',
            'workflow_type' => 'Child',
            'parent_close_policy' => 'abandon',
        ]], $out);
    }

    public function testStartChildWorkflowPreservesRetryPolicyAndTimeouts(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [2, 8],
                    'non_retryable_error_types' => ['ValidationError'],
                ],
                'execution_timeout_seconds' => 600,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_child_workflow',
            'workflow_type' => 'Child',
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [2, 8],
                'non_retryable_error_types' => ['ValidationError'],
            ],
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ]], $out);
    }

    public function testStartChildWorkflowRejectsInvalidRetryPolicy(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'retry_policy' => [
                    'max_attempts' => 0,
                    'backoff_seconds' => [1, -1],
                ],
            ],
        ]);
    }

    public function testContinueAsNewPassesThroughOptionalWorkflowTypeAndQueue(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'continue_as_new',
                'workflow_type' => 'NextWorkflow',
                'queue' => 'next-workers',
            ],
        ]);

        $this->assertSame([[
            'type' => 'continue_as_new',
            'workflow_type' => 'NextWorkflow',
            'queue' => 'next-workers',
        ]], $out);
    }

    public function testContinueAsNewDropsPayloadCodecWithoutArguments(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'continue_as_new',
                'payload_codec' => ' Workflow\\Serializers\\Y ',
            ],
        ]);
    }

    public function testContinueAsNewRejectsUnknownPayloadCodec(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'continue_as_new',
                'arguments' => Serializer::serialize(['next']),
                'payload_codec' => 'not-a-codec',
            ],
        ]);
    }

    public function testContinueAsNewTakesPayloadCodecFromArgumentsEnvelope(): void
    {
        $arguments = Serializer::serializeWithCodec('avro', ['next']);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'continue_as_new',
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => $arguments,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'continue_as_new',
            'arguments' => $arguments,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testStartServiceOperationNormalizesPayloadEnvelopeAndCallerMetadata(): void
    {
        $requestPayload = Serializer::serializeWithCodec('avro', [
            'amount' => 4200,
            'currency' => 'USD',
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_service_operation',
                'endpoint_name' => 'payments',
                'service_name' => 'PythonPayments',
                'operation_name' => 'authorize',
                'request_payload' => [
                    'codec' => 'avro',
                    'blob' => $requestPayload,
                ],
                'service_call_id' => 'svc-php-1',
                'idempotency_key' => 'workflow-service-operation:wf-1:run-1:1',
                'mode_override' => 'ASYNC',
                'wait_for' => 'ACCEPTED',
                'wait_timeout_seconds' => 0,
                'metadata' => [
                    'caller_sdk_language' => 'workflow-php',
                    'service_sdk_language' => 'sdk-python',
                ],
                'principal_roles' => ['workflow'],
            ],
        ]);

        $this->assertSame([[
            'type' => 'start_service_operation',
            'endpoint_name' => 'payments',
            'service_name' => 'PythonPayments',
            'operation_name' => 'authorize',
            'request_payload' => $requestPayload,
            'payload_codec' => 'avro',
            'service_call_id' => 'svc-php-1',
            'idempotency_key' => 'workflow-service-operation:wf-1:run-1:1',
            'mode_override' => 'async',
            'wait_for' => 'accepted',
            'wait_timeout_seconds' => 0,
            'metadata' => [
                'caller_sdk_language' => 'workflow-php',
                'service_sdk_language' => 'sdk-python',
            ],
            'principal_roles' => ['workflow'],
        ]], $out);
    }

    public function testCompleteUpdateUnwrapsEnvelope(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [
            'approved' => true,
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_update',
                'update_id' => '01UPDATE000000000000000001',
                'result' => [
                    'codec' => 'avro',
                    'blob' => $blob,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_update',
            'update_id' => '01UPDATE000000000000000001',
            'result' => $blob,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testCompleteUpdateAcceptsExplicitPayloadCodec(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [
            'approved' => true,
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_update',
                'update_id' => '01UPDATE000000000000000001',
                'result' => $blob,
                'payload_codec' => 'avro',
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_update',
            'update_id' => '01UPDATE000000000000000001',
            'result' => $blob,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testCompleteUpdateRequiresUpdateId(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_update',
                'result' => '"ok"',
            ],
        ]);
    }

    public function testCompleteUpdateRejectsNonStringResultPayload(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'complete_update',
                'update_id' => '01UPDATE000000000000000001',
                'result' => 42,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.result', $errors);
        $this->assertStringContainsString(
            'must be a string or a payload envelope',
            $errors['commands.0.result'][0],
        );
    }

    public function testFailUpdatePreservesOptionalFields(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => '01UPDATE000000000000000002',
                'message' => 'boom',
                'exception_class' => 'App\\Exceptions\\UpdateBoom',
                'exception_type' => 'update_boom',
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame([[
            'type' => 'fail_update',
            'update_id' => '01UPDATE000000000000000002',
            'message' => 'boom',
            'exception_class' => 'App\\Exceptions\\UpdateBoom',
            'exception_type' => 'update_boom',
            'non_retryable' => true,
        ]], $out);
    }

    public function testFailUpdateRequiresMessage(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => '01UPDATE000000000000000003',
                'message' => '',
            ],
        ]);
    }

    public function testRecordSideEffectRequiresStringResult(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_side_effect',
                'result' => 42,
            ],
        ]);
    }

    public function testRecordSideEffectUnwrapsEnvelopeWithPayloadCodec(): void
    {
        $blob = Serializer::serializeWithCodec('avro', [
            'seed' => 123,
        ]);

        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_side_effect',
                'result' => [
                    'codec' => 'avro',
                    'blob' => $blob,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'record_side_effect',
            'result' => $blob,
            'payload_codec' => 'avro',
        ]], $out);
    }

    public function testRecordVersionMarkerRequiresAllIntFields(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => 'c1',
                'version' => 1,
                'min_supported' => 1,
                // missing max_supported
            ],
        ]);
    }

    public function testRecordVersionMarkerCoercesIntegers(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => ' feature-x ',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 3,
            ],
        ]);

        $this->assertSame([[
            'type' => 'record_version_marker',
            'change_id' => 'feature-x',
            'version' => 2,
            'min_supported' => 1,
            'max_supported' => 3,
        ]], $out);
    }

    public function testUpsertSearchAttributesRequiresNonEmpty(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [],
            ],
        ]);
    }

    public function testUpsertMemoCanonicalizesLanguageNeutralEntries(): void
    {
        $entries = [
            'status' => 'waiting',
            'attempt' => 2,
            'remove_me' => null,
            'payload_reference' => [
                'external_storage' => [
                    'key' => 'opaque-runtime-reference',
                    'codec' => 'avro',
                ],
                'codec' => 'avro',
            ],
        ];
        $out = WorkflowCommandNormalizer::normalize([[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::envelope($entries),
        ]]);

        $this->assertSame('upsert_memo', $out[0]['type']);
        $this->assertSame(MemoPayload::envelope($entries), $out[0]['entries']);
        $this->assertEquals([
            'attempt' => 2,
            'payload_reference' => $entries['payload_reference'],
            'remove_me' => null,
            'status' => 'waiting',
        ], MemoPayload::decodeEntries($out[0]['entries']));
    }

    public function testUpsertMemoRejectsEmptyAndOversizedEntries(): void
    {
        $emptyErrors = $this->normalizeAndCaptureErrors([[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::envelope([]),
        ]]);

        $this->assertArrayHasKey('commands.0.entries', $emptyErrors);

        $oversizedErrors = $this->normalizeAndCaptureErrors([[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::envelope([
                'too_large' => str_repeat('x', WorkflowMemo::MAX_VALUE_SIZE_BYTES + 1),
            ]),
        ]]);

        $this->assertArrayHasKey('commands.0.entries.too_large', $oversizedErrors);
    }

    public function testUpsertMemoAllowsAnAtLimitMemoToBeReplacedInOnePatch(): void
    {
        $entries = [];
        for ($index = 0; $index < WorkflowMemo::MAX_MEMOS_PER_RUN; $index++) {
            $entries[sprintf('existing_%03d', $index)] = null;
        }
        $entries['replacement'] = 'current';

        $out = WorkflowCommandNormalizer::normalize([[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::envelope($entries),
        ]]);

        $decoded = MemoPayload::decodeEntries($out[0]['entries']);
        $this->assertCount(WorkflowMemo::MAX_MEMOS_PER_RUN + 1, $decoded);
        $this->assertSame('current', $decoded['replacement']);
    }

    public function testUpsertMemoRejectsRawJsonEntriesAndJsonEnvelope(): void
    {
        $rawErrors = $this->normalizeAndCaptureErrors([[
            'type' => 'upsert_memo',
            'entries' => [
                'status' => 'waiting',
            ],
        ]]);
        $jsonErrors = $this->normalizeAndCaptureErrors([[
            'type' => 'upsert_memo',
            'entries' => [
                'codec' => 'json',
                'blob' => '{"status":"waiting"}',
            ],
        ]]);

        $this->assertArrayHasKey('commands.0.entries', $rawErrors);
        $this->assertStringContainsString('standard Avro', $rawErrors['commands.0.entries'][0]);
        $this->assertArrayHasKey('commands.0.entries.codec', $jsonErrors);
        $this->assertStringContainsString('unsupported_payload_codec', $jsonErrors['commands.0.entries.codec'][0]);
    }

    public function testUpsertMemoRejectsMalformedAvroEnvelope(): void
    {
        $errors = $this->normalizeAndCaptureErrors([[
            'type' => 'upsert_memo',
            'entries' => [
                'codec' => 'avro',
                'blob' => base64_encode('not-an-avro-single-object'),
            ],
        ]]);

        $this->assertArrayHasKey('commands.0.entries', $errors);
        $this->assertStringContainsString('invalid_payload_framing', $errors['commands.0.entries'][0]);
    }

    public function testUpsertSearchAttributesPreservesCompatibleDeclaredTypes(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'score' => 5,
                    'tags' => ['alpha', 'beta'],
                ],
                'attribute_types' => [
                    'score' => WorkflowSearchAttribute::TYPE_FLOAT,
                    'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
                ],
            ],
        ]);

        $this->assertSame([[
            'type' => 'upsert_search_attributes',
            'attributes' => [
                'score' => 5,
                'tags' => ['alpha', 'beta'],
            ],
            'attribute_types' => [
                'score' => WorkflowSearchAttribute::TYPE_FLOAT,
                'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
            ],
        ]], $out);
    }

    public function testUpsertSearchAttributesRejectsDeclaredTypeIncompatibleWithValue(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'upsert_search_attributes',
                'attributes' => [
                    'tags' => 'alpha',
                ],
                'attribute_types' => [
                    'tags' => WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.attribute_types', $errors);
        $this->assertStringContainsString(
            'not compatible with declared type [keyword_list]',
            $errors['commands.0.attribute_types'][0],
        );
    }

    public function testOpenConditionWaitWithoutOptionalFieldsNormalizes(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
        ]], $out);
    }

    public function testOpenConditionWaitTrimsKeyAndPreservesTimeout(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'condition_key' => '  order-ready ',
                'condition_definition_fingerprint' => ' fp-1 ',
                'condition_wait_occurrence_id' => ' rust:condition-wait:0 ',
                'timeout_seconds' => 30,
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'order-ready',
            'condition_definition_fingerprint' => 'fp-1',
            'condition_wait_occurrence_id' => 'rust:condition-wait:0',
            'timeout_seconds' => 30,
        ]], $out);
    }

    public function testOpenConditionWaitRejectsNegativeTimeout(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => -1,
            ],
        ]);
    }

    public function testOpenConditionWaitRejectsNonIntegerTimeout(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'timeout_seconds' => '30',
            ],
        ]);
    }

    public function testOpenConditionWaitAllowsZeroTimeout(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_condition_wait',
                'condition_key' => 'k',
                'timeout_seconds' => 0,
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'k',
            'timeout_seconds' => 0,
        ]], $out);
    }

    public function testOpenSignalWaitTrimsNameAndPreservesTimeout(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_signal_wait',
                'signal_name' => ' increment ',
                'timeout_seconds' => 30,
            ],
        ]);

        $this->assertSame([[
            'type' => 'open_signal_wait',
            'signal_name' => 'increment',
            'timeout_seconds' => 30,
        ]], $out);
    }

    public function testOpenSignalWaitRequiresName(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_signal_wait',
            ],
        ]);
    }

    public function testOpenSignalWaitRejectsNegativeTimeout(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'open_signal_wait',
                'signal_name' => 'increment',
                'timeout_seconds' => -1,
            ],
        ]);
    }

    public function testUnknownCommandTypeRejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'type' => 'do_a_barrel_roll',
            ],
        ]);
    }

    public function testMissingTypeFieldRejected(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowCommandNormalizer::normalize([
            [
                'payload' => 'nope',
            ],
        ]);
    }

    public function testRetryPolicyRejectedOnCompleteWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
                'retry_policy' => [
                    'max_attempts' => 3,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.retry_policy', $errors);
        $this->assertStringContainsString(
            'not valid on a complete_workflow command',
            $errors['commands.0.retry_policy'][0],
        );
        $this->assertStringContainsString('schedule_activity', $errors['commands.0.retry_policy'][0]);
        $this->assertStringContainsString('start_child_workflow', $errors['commands.0.retry_policy'][0]);
    }

    public function testRetryPolicyRejectedOnFailWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'retry_policy' => [
                    'max_attempts' => 3,
                ],
            ],
        ]);

        $this->assertArrayHasKey('commands.0.retry_policy', $errors);
        $this->assertStringContainsString(
            'Workflow failure itself is non-retryable',
            $errors['commands.0.retry_policy'][0],
        );
    }

    public function testStartToCloseTimeoutRejectedOnChildWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'start_to_close_timeout' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.start_to_close_timeout', $errors);
        $this->assertStringContainsString(
            'only applies to a schedule_activity command',
            $errors['commands.0.start_to_close_timeout'][0],
        );
    }

    public function testHeartbeatTimeoutRejectedOnFailWorkflow(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'heartbeat_timeout' => 30,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.heartbeat_timeout', $errors);
    }

    public function testExecutionTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'execution_timeout_seconds' => 600,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.execution_timeout_seconds', $errors);
        $this->assertStringContainsString(
            'only applies to a start_child_workflow command',
            $errors['commands.0.execution_timeout_seconds'][0],
        );
    }

    public function testRunTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'run_timeout_seconds' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.run_timeout_seconds', $errors);
    }

    public function testNonRetryableRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'non_retryable' => true,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.non_retryable', $errors);
        $this->assertStringContainsString(
            'retry_policy.non_retryable_error_types',
            $errors['commands.0.non_retryable'][0],
        );
    }

    public function testParentClosePolicyRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'parent_close_policy' => 'abandon',
            ],
        ]);

        $this->assertArrayHasKey('commands.0.parent_close_policy', $errors);
    }

    public function testTimeoutSecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'timeout_seconds' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.timeout_seconds', $errors);
        $this->assertStringContainsString(
            'For activities use start_to_close_timeout',
            $errors['commands.0.timeout_seconds'][0],
        );
    }

    public function testDelaySecondsRejectedOnScheduleActivity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'delay_seconds' => 30,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.delay_seconds', $errors);
    }

    public function testNullScopeFieldsAreIgnored(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'complete_workflow',
                'result' => '"ok"',
                'retry_policy' => null,
                'start_to_close_timeout' => null,
                'non_retryable' => null,
                'parent_close_policy' => null,
            ],
        ]);

        $this->assertSame([[
            'type' => 'complete_workflow',
            'result' => '"ok"',
        ]], $out);
    }

    public function testActivityScheduleToCloseSmallerThanStartToCloseRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 120,
                'schedule_to_close_timeout' => 60,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.schedule_to_close_timeout', $errors);
        $this->assertStringContainsString(
            'must be greater than or equal to start_to_close_timeout',
            $errors['commands.0.schedule_to_close_timeout'][0],
        );
    }

    public function testActivityScheduleToCloseEqualToStartToCloseAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 120,
                'schedule_to_close_timeout' => 120,
            ],
        ]);

        $this->assertSame(120, $out[0]['start_to_close_timeout']);
        $this->assertSame(120, $out[0]['schedule_to_close_timeout']);
    }

    public function testActivityHeartbeatLargerThanStartToCloseRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 60,
                'heartbeat_timeout' => 120,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.heartbeat_timeout', $errors);
        $this->assertStringContainsString(
            'must be less than or equal to start_to_close_timeout',
            $errors['commands.0.heartbeat_timeout'][0],
        );
    }

    public function testActivityHeartbeatEqualToStartToCloseAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'start_to_close_timeout' => 60,
                'heartbeat_timeout' => 60,
            ],
        ]);

        $this->assertSame(60, $out[0]['heartbeat_timeout']);
    }

    public function testActivityTimeoutOrderingNotEnforcedWhenFieldsMissing(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'schedule_activity',
                'activity_type' => 'SendEmail',
                'heartbeat_timeout' => 600,
            ],
        ]);

        $this->assertSame(600, $out[0]['heartbeat_timeout']);
    }

    public function testChildWorkflowExecutionTimeoutSmallerThanRunTimeoutRejected(): void
    {
        $errors = $this->normalizeAndCaptureErrors([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'execution_timeout_seconds' => 60,
                'run_timeout_seconds' => 120,
            ],
        ]);

        $this->assertArrayHasKey('commands.0.execution_timeout_seconds', $errors);
        $this->assertStringContainsString(
            'must be greater than or equal to run_timeout_seconds',
            $errors['commands.0.execution_timeout_seconds'][0],
        );
    }

    public function testChildWorkflowExecutionTimeoutEqualToRunTimeoutAccepted(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'start_child_workflow',
                'workflow_type' => 'Child',
                'execution_timeout_seconds' => 60,
                'run_timeout_seconds' => 60,
            ],
        ]);

        $this->assertSame(60, $out[0]['execution_timeout_seconds']);
        $this->assertSame(60, $out[0]['run_timeout_seconds']);
    }

    public function testFailWorkflowAcceptsNonRetryableScope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_workflow',
                'message' => 'boom',
                'non_retryable' => false,
            ],
        ]);

        $this->assertSame(false, $out[0]['non_retryable']);
    }

    public function testFailUpdateAcceptsNonRetryableScope(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'fail_update',
                'update_id' => 'upd-1',
                'message' => 'boom',
                'non_retryable' => true,
            ],
        ]);

        $this->assertSame(true, $out[0]['non_retryable']);
    }

    public function testParallelMetadataIsPreservedForActivityChildAndTimerCommands(): void
    {
        $commands = [];
        foreach ([
            ['schedule_activity', 'activity_type', 'fetch'],
            ['start_child_workflow', 'workflow_type', 'child'],
            ['start_timer', 'delay_seconds', 5],
        ] as $index => [$type, $detailField, $detail]) {
            $kind = 'mixed';
            $entry = [
                'parallel_group_id' => 'parallel-calls:1:3',
                'parallel_group_kind' => $kind,
                'parallel_group_base_sequence' => 1,
                'parallel_group_size' => 3,
                'parallel_group_index' => $index,
            ];
            $commands[] = [
                'type' => $type,
                $detailField => $detail,
                ...$entry,
                'parallel_group_path' => [$entry],
            ];
        }

        $normalized = WorkflowCommandNormalizer::normalize($commands);

        $this->assertSame(['parallel-calls:1:3', 'parallel-calls:1:3', 'parallel-calls:1:3'], array_column(
            $normalized,
            'parallel_group_id',
        ));
        $this->assertSame([0, 1, 2], array_column($normalized, 'parallel_group_index'));
        $this->assertSame('parallel-calls:1:3', $normalized[2]['parallel_group_path'][0]['parallel_group_id']);
    }

    public function testParallelMetadataRejectsPartialOrIncompatibleIdentity(): void
    {
        $errors = $this->normalizeAndCaptureErrors([[
            'type' => 'schedule_activity',
            'activity_type' => 'fetch',
            'parallel_group_id' => 'parallel-activities:1:2',
        ]]);
        $this->assertArrayHasKey('commands.0.parallel_group_path', $errors);

        $entry = [
            'parallel_group_id' => 'parallel-children:1:1',
            'parallel_group_kind' => 'child',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 1,
            'parallel_group_index' => 0,
        ];
        $errors = $this->normalizeAndCaptureErrors([[
            'type' => 'schedule_activity',
            'activity_type' => 'fetch',
            ...$entry,
            'parallel_group_path' => [$entry],
        ]]);
        $this->assertArrayHasKey('commands.0.parallel_group_path', $errors);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return array<string, list<string>>
     */
    private function normalizeAndCaptureErrors(array $commands): array
    {
        try {
            WorkflowCommandNormalizer::normalize($commands);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected ValidationException was not thrown.');
    }
}
