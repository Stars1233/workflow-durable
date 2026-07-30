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

    public function testSharedMalformedFramesUseCanonicalBase64AndFailAsDeclared(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($fixture['malformed_frames'] as $case) {
            $wire = $case['wire_base64'];
            $bytes = base64_decode($wire, true);
            self::assertIsString($bytes, "{$case['name']} must use valid Base64.");
            self::assertSame($wire, base64_encode($bytes), "{$case['name']} must use canonical Base64.");

            if ($case['name'] === 'decoded_non_magic_bytes') {
                self::assertSame('%%%', $bytes, 'JSUl is valid Base64 containing invalid Avro framing bytes.');
            }

            try {
                Avro::unserialize($wire);
                self::fail("Expected {$case['name']} Avro frame to fail.");
            } catch (CodecDecodeException $exception) {
                self::assertStringContainsString($case['error'], $exception->getMessage());

                if ($case['name'] === 'decoded_non_magic_bytes') {
                    self::assertStringContainsString(
                        'expected Avro single-object magic c301',
                        $exception->detail,
                        'JSUl must reach Avro framing validation after Base64 decoding.',
                    );
                }
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

    public function testCheckedInCodecRegressionCorpusUsesTheOfficialBinding(): void
    {
        $paths = glob(__DIR__ . '/../../Fixtures/V2/CodecRegression/*.json') ?: [];
        sort($paths);
        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('durable-workflow.codec-regression/v1', $fixture['fixture_schema'] ?? null);
            self::assertContains('php', $fixture['bindings'] ?? []);
            self::assertSame(Avro::valueSchemaFingerprint(), $fixture['protocol']['fingerprint'] ?? null);

            $value = self::taggedValue($fixture['value']);
            $wire = $fixture['framing']['wire_base64'] ?? null;
            $operation = $fixture['failure_policy']['operation'] ?? null;
            $error = $fixture['failure_policy']['error'] ?? null;

            if ($operation === 'round_trip') {
                self::assertIsString($wire);
                self::assertSame($wire, Avro::serialize($value), $fixture['id']);
                $decoded = Avro::unserialize($wire);
                self::assertEquals($value, $decoded, $fixture['id']);
                self::assertSame($wire, Avro::serialize($decoded), $fixture['id']);
                continue;
            }

            try {
                if ($operation === 'decode_reject') {
                    self::assertIsString($wire);
                    Avro::unserialize($wire);
                } elseif ($operation === 'encode_reject') {
                    Avro::serialize($value);
                } else {
                    self::fail("Unsupported failure policy in {$path}.");
                }
                self::fail("Expected {$fixture['id']} to be rejected.");
            } catch (InvalidArgumentException|CodecDecodeException $exception) {
                self::assertIsString($error);
                self::assertStringContainsString($error, $exception->getMessage());
            }
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

    /**
     * @param array<string, mixed> $value
     */
    private static function taggedValue(array $value): mixed
    {
        return match ($value['type'] ?? null) {
            'null' => null,
            'boolean' => (bool) $value['value'],
            'long' => (int) $value['value'],
            'double' => (float) $value['value'],
            'bytes' => AvroBinaryValue::fromBytes((string) base64_decode((string) $value['base64'], true)),
            'string' => (string) $value['value'],
            'array' => array_map(self::taggedValue(...), is_array($value['items'] ?? null) ? $value['items'] : []),
            'map' => AvroMapValue::fromPairs(array_map(
                static fn (array $entry): array => [(string) $entry['key'], self::taggedValue($entry['value'])],
                is_array($value['entries'] ?? null) ? $value['entries'] : [],
            )),
            default => throw new InvalidArgumentException('Unsupported tagged corpus value.'),
        };
    }
}
