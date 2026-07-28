<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\CodecDecodeException;

final class AvroValueProtocolTest extends TestCase
{
    public function testCanonicalSchemaFingerprintAndGoldenBytes(): void
    {
        self::assertSame(
            trim(
                (string) file_get_contents(
                    __DIR__ . '/../../../resources/protocol/durable_workflow.protocol.Value.v1.avsc'
                )
            ),
            Avro::valueSchemaJson(),
        );
        self::assertSame('e2a33dff55802237', Avro::valueSchemaFingerprint());

        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $golden = array_column($fixture['cases'], 'wire_base64', 'name');
        $cases = [
            'null' => null,
            'boolean_false' => false,
            'boolean_true' => true,
            'long_min' => PHP_INT_MIN,
            'long_max' => PHP_INT_MAX,
            'long_7' => 7,
            'double_7' => 7.0,
            'negative_zero' => -0.0,
            'bytes_00ff' => AvroBinaryValue::fromBytes("\x00\xFF"),
            'string_utf8' => 'héllo',
            'array' => [null, true, 7, 7.0, AvroBinaryValue::fromBytes("\x00\xFF"), 'text'],
            'map' => [
                'a' => 1,
                'b' => [false],
            ],
            'map_empty' => AvroMapValue::fromPairs([]),
            'map_key_0' => AvroMapValue::fromPairs([['0', 'zero']]),
            'map_keys_0_1' => AvroMapValue::fromPairs([['0', 'zero'], ['1', 'one']]),
            'nested' => [
                'items' => [[
                    'enabled' => true,
                ], AvroBinaryValue::fromBytes('bytes'), -2.5],
            ],
        ];

        foreach ($cases as $name => $value) {
            $blob = Avro::serialize($value);
            self::assertSame($golden[$name], $blob, $name);
            $decoded = Avro::unserialize($blob);
            self::assertEquals($value, $decoded, $name);
            self::assertSame($blob, Avro::serialize($decoded), $name);
        }
    }

    public function testSharedTrailingBytesFrameIsRejected(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($fixture['malformed_frames'] as $case) {
            try {
                Avro::unserialize($case['wire_base64']);
                self::fail("Expected {$case['name']} to fail.");
            } catch (CodecDecodeException $exception) {
                self::assertStringContainsString($case['error'], $exception->getMessage());
            }
        }
    }

    public function testSharedAlternateMapOrdersDecodeToTheSameNestedValue(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expected = [
            'outer' => [[
                'left' => 1,
                'right' => AvroBinaryValue::fromBytes('x'),
            ]],
            'tail' => 'done',
        ];

        foreach ($fixture['alternate_map_orders'][0]['wire_base64'] as $blob) {
            $decoded = Avro::unserialize($blob);
            self::assertEquals($expected, $decoded);
            self::assertEquals($expected, Avro::unserialize(Avro::serialize($decoded)));
        }
    }

    public function testRejectsPolicyViolations(): void
    {
        foreach (
            [
                'invalid_map_key' => [
                    1 => 'one',
                ],
                'non_finite_float' => INF,
                'invalid_utf8_string' => "\xFF",
            ] as $reason => $value
        ) {
            try {
                Avro::serialize($value);
                self::fail("Expected {$reason}.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function testRejectsUnknownFingerprintAndLegacyWrapper(): void
    {
        $bytes = base64_decode(Avro::serialize(null), true);
        $bytes[2] = chr(ord($bytes[2]) ^ 0xFF);

        try {
            Avro::unserialize(base64_encode($bytes));
            self::fail('Expected unknown fingerprint failure.');
        } catch (CodecDecodeException $exception) {
            self::assertStringContainsString('unsupported_payload_schema', $exception->getMessage());
        }

        $this->expectException(CodecDecodeException::class);
        $this->expectExceptionMessage('invalid_payload_framing');
        Avro::unserialize(base64_encode("\x00legacy-wrapper"));
    }

    public function testRecursiveReaderEmitsNoWarnings(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING);
        try {
            $value = [
                'nested' => [[
                    'value' => true,
                ]],
            ];
            self::assertSame($value, Avro::unserialize(Avro::serialize($value)));
        } finally {
            restore_error_handler();
        }
    }
}
