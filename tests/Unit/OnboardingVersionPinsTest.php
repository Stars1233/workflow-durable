<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OnboardingVersionPinsTest extends TestCase
{
    public function testReadmeUsesComposerPrereleaseChannels(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertIsString($readme);
        self::assertDoesNotMatchRegularExpression(
            '/\bv?\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+\b|\b\d+\.\d+\.\d+(?:a|b|rc)\d+\b/i',
            $readme,
        );
        self::assertStringContainsString(
            'https://durable-workflow.com/install-sdk.sh | sh -s -- workflow',
            $readme,
        );
        self::assertStringContainsString('https://durable-workflow.com/install-sdk.sh | sh -s -- php', $readme);
    }
}
