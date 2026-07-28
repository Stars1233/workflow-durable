<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;
use Workflow\V2\Support\PrereleaseAvroValueMigration;

final class PrereleaseAvroValueMigrationTest extends TestCase
{
    private const WRAPPER_SCHEMA = '{"type":"record","name":"Payload","namespace":"durable_workflow","fields":[{"name":"json","type":"string"},{"name":"version","type":"int","default":1}]}';

    public function testOneTimeAdapterConvertsAndVerifiesLegacyWrapper(): void
    {
        $legacy = self::legacyBlob([
            'int' => 7,
            'double' => 7.0,
            'nested' => [true, 'text'],
        ]);

        self::assertTrue(PrereleaseAvroValueMigration::isLegacyBlob($legacy));
        $converted = PrereleaseAvroValueMigration::convert($legacy);

        self::assertStringStartsWith(
            Avro::SINGLE_OBJECT_MAGIC . Avro::VALUE_SCHEMA_FINGERPRINT,
            (string) base64_decode($converted, true),
        );
        self::assertSame(
            [
                'int' => 7,
                'double' => 7.0,
                'nested' => [true, 'text'],
            ],
            Avro::unserialize($converted),
        );
    }

    public function testRuntimeDecoderDoesNotRetainPrereleaseFallback(): void
    {
        $legacy = self::legacyBlob([
            'retained' => true,
        ]);

        $this->expectException(CodecDecodeException::class);
        $this->expectExceptionMessage('invalid_payload_framing');
        Avro::unserialize($legacy);
    }

    private static function legacyBlob(mixed $value): string
    {
        $io = new AvroStringIO();
        $io->write("\x00");
        (new AvroIODatumWriter(Avro::parseSchema(self::WRAPPER_SCHEMA)))->write(
            [
                'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'version' => 1,
            ],
            new AvroIOBinaryEncoder($io),
        );

        return base64_encode($io->string());
    }
}
