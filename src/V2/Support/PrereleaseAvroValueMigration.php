<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use RuntimeException;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecRegistry;

/**
 * One-time reader for prerelease JSON-in-Avro payloads.
 *
 * Runtime decoding intentionally does not call this class. It exists only so
 * operators can back up and rewrite retained prerelease histories before
 * adopting the fixed Value schema.
 */
final class PrereleaseAvroValueMigration
{
    private const WRAPPER_SCHEMA = '{"type":"record","name":"Payload","namespace":"durable_workflow","fields":[{"name":"json","type":"string"},{"name":"version","type":"int","default":1}]}';

    private static ?AvroSchema $schema = null;

    public static function isLegacyBlob(mixed $blob): bool
    {
        if (! is_string($blob) || $blob === '') {
            return false;
        }
        $bytes = base64_decode($blob, true);

        return is_string($bytes) && str_starts_with($bytes, "\x00");
    }

    public static function convert(string $blob): string
    {
        $value = self::decode($blob);
        $converted = Avro::serialize($value);
        if (Avro::unserialize($converted) !== $value) {
            throw new RuntimeException('Prerelease Avro migration verification failed.');
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     payload: array<string, mixed>,
     *     legacy_count: int,
     *     external_backups: list<array{path: string, envelope: array<string, mixed>, legacy_blob: string}>
     * }
     */
    public static function inspectHistoryPayload(array $payload, ?string $namespace): array
    {
        return self::transformHistoryPayload($payload, $namespace, false);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     payload: array<string, mixed>,
     *     legacy_count: int,
     *     external_backups: list<array{path: string, envelope: array<string, mixed>, legacy_blob: string}>
     * }
     */
    public static function convertHistoryPayload(array $payload, ?string $namespace): array
    {
        return self::transformHistoryPayload($payload, $namespace, true);
    }

    public static function decode(string $blob): mixed
    {
        $bytes = base64_decode($blob, true);
        if (! is_string($bytes) || ! str_starts_with($bytes, "\x00")) {
            throw new RuntimeException('Expected a prerelease 0x00 Avro wrapper.');
        }

        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            $reader = new AvroIODatumReader(self::$schema ??= Avro::parseSchema(self::WRAPPER_SCHEMA));
            $record = $reader->read(new AvroIOBinaryDecoder(new AvroStringIO(substr($bytes, 1))));
        } finally {
            restore_error_handler();
        }

        if (
            ! is_array($record)
            || ! is_string($record['json'] ?? null)
            || ($record['version'] ?? null) !== 1
        ) {
            throw new RuntimeException('Invalid prerelease Avro wrapper record.');
        }

        return json_decode($record['json'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     payload: array<string, mixed>,
     *     legacy_count: int,
     *     external_backups: list<array{path: string, envelope: array<string, mixed>, legacy_blob: string}>
     * }
     */
    private static function transformHistoryPayload(array $payload, ?string $namespace, bool $convert): array
    {
        $legacyCount = 0;
        $externalBackups = [];
        $transformed = self::transformHistoryValue(
            $payload,
            $namespace,
            null,
            '$',
            $convert,
            $legacyCount,
            $externalBackups,
        );

        if (! is_array($transformed)) {
            throw new RuntimeException('History payload migration produced an invalid root value.');
        }

        return [
            'payload' => $transformed,
            'legacy_count' => $legacyCount,
            'external_backups' => $externalBackups,
        ];
    }

    /**
     * @param list<array{path: string, envelope: array<string, mixed>, legacy_blob: string}> $externalBackups
     */
    private static function transformHistoryValue(
        mixed $value,
        ?string $namespace,
        ?string $codec,
        string $path,
        bool $convert,
        int &$legacyCount,
        array &$externalBackups,
    ): mixed {
        if (is_string($value)) {
            if (! self::isAvroCodec($codec)) {
                return $value;
            }

            if (ExternalPayloads::isStoredReference($value)) {
                $envelope = ExternalPayloads::storedEnvelope($value);
                if ($envelope === null) {
                    return $value;
                }
                $legacyBlob = ExternalPayloads::payloadBlob($envelope, 'avro', $namespace);
                if (! is_string($legacyBlob) || ! self::isLegacyBlob($legacyBlob)) {
                    return $value;
                }
                $legacyCount++;
                $externalBackups[] = [
                    'path' => $path,
                    'envelope' => $envelope,
                    'legacy_blob' => $legacyBlob,
                ];

                return $convert
                    ? ExternalPayloads::replaceStoredReference($value, self::convert($legacyBlob), $namespace)
                    : $value;
            }

            if (! self::isLegacyBlob($value)) {
                return $value;
            }
            $legacyCount++;

            return $convert ? self::convert($value) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $envelopeCodec = isset($value['codec']) && is_string($value['codec'])
            ? $value['codec']
            : $codec;
        if (
            self::isAvroCodec($envelopeCodec)
            && isset($value['external_storage'])
            && is_array($value['external_storage'])
        ) {
            $legacyBlob = ExternalPayloads::payloadBlob($value, 'avro', $namespace);
            if (is_string($legacyBlob) && self::isLegacyBlob($legacyBlob)) {
                $legacyCount++;
                $externalBackups[] = [
                    'path' => $path,
                    'envelope' => $value,
                    'legacy_blob' => $legacyBlob,
                ];

                return $convert
                    ? ExternalPayloads::replaceStoredEnvelope($value, self::convert($legacyBlob), $namespace)
                    : $value;
            }
        }

        if (
            self::isAvroCodec($envelopeCodec)
            && isset($value['blob'])
            && is_string($value['blob'])
            && self::isLegacyBlob($value['blob'])
        ) {
            $legacyCount++;
            if ($convert) {
                $value['blob'] = self::convert($value['blob']);
            }
        }

        $defaultCodec = isset($value['payload_codec']) && is_string($value['payload_codec'])
            ? $value['payload_codec']
            : $envelopeCodec;
        foreach ($value as $key => $item) {
            $itemPath = $path . (is_int($key) ? "[{$key}]" : '.' . $key);
            $value[$key] = self::transformHistoryValue(
                $item,
                $namespace,
                self::codecForKey($value, $key, $defaultCodec),
                $itemPath,
                $convert,
                $legacyCount,
                $externalBackups,
            );
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $container
     */
    private static function codecForKey(array $container, int|string $key, ?string $default): ?string
    {
        if (! is_string($key)) {
            return $default;
        }

        $codecKeys = [$key . '_payload_codec'];
        if (str_ends_with($key, '_payload')) {
            $codecKeys[] = preg_replace('/_payload\z/', '_payload_codec', $key);
        }

        foreach ($codecKeys as $codecKey) {
            if (
                is_string($codecKey)
                && isset($container[$codecKey])
                && is_string($container[$codecKey])
            ) {
                return $container[$codecKey];
            }
        }

        return $default;
    }

    private static function isAvroCodec(?string $codec): bool
    {
        if (! is_string($codec) || $codec === '') {
            return false;
        }

        try {
            return CodecRegistry::canonicalize($codec) === 'avro';
        } catch (\Throwable) {
            return false;
        }
    }
}
