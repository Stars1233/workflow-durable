<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use RuntimeException;

/**
 * Canonical, machine-readable mirror of the public platform conformance suite.
 *
 * The complete authority document ships with the package so server endpoints,
 * release gates, and third-party harnesses all consume the same target,
 * category, and pass/fail semantics as the public docs site.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `platform_conformance_suite` key.
 */
final class PlatformConformanceSuite
{
    public const SCHEMA = 'durable-workflow.v2.platform-conformance.suite';

    public const VERSION = 38;

    public const MIRROR_SHA256 = 'b577595319219280fbc8e460ba42790363ab63f78d4eec1ce1cf0b9846562db9';

    public const FIXTURE_SOURCE_REVISION = 'c600505a0c39b2b2220b2f2426053315aa61f1f2';

    public const RESULT_SCHEMA = 'durable-workflow.v2.platform-conformance.result';

    public const RESULT_VERSION = 1;

    public const AUTHORITY_DOC = 'docs/platform-conformance.md';

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/platform-conformance';

    public const CATEGORY_STATUS_STABLE = 'stable';

    public const CATEGORY_STATUS_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_FULL = 'full';

    public const CONFORMANCE_LEVEL_PARTIAL = 'partial';

    public const CONFORMANCE_LEVEL_PROVISIONAL = 'provisional';

    public const CONFORMANCE_LEVEL_NONCONFORMING = 'nonconforming';

    public const CONFORMANCE_LEVELS = [
        self::CONFORMANCE_LEVEL_FULL,
        self::CONFORMANCE_LEVEL_PARTIAL,
        self::CONFORMANCE_LEVEL_PROVISIONAL,
        self::CONFORMANCE_LEVEL_NONCONFORMING,
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/resources/platform-conformance-contract.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Platform conformance suite mirror is missing at {$path}.");
        }

        if (hash('sha256', $json) !== self::MIRROR_SHA256) {
            throw new RuntimeException(
                'Platform conformance suite mirror digest does not match the packaged authority.'
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Platform conformance suite mirror is not valid JSON.', 0, $exception);
        }

        if (! is_array(
            $decoded
        ) || ($decoded['schema'] ?? null) !== self::SCHEMA || ($decoded['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Platform conformance suite mirror identity does not match the class contract.');
        }

        self::assertStableFixtureSources($decoded);

        self::$manifest = $decoded;

        return self::$manifest;
    }

    /**
     * @return array<int, string>
     */
    public static function targetNames(): array
    {
        return array_keys(self::manifest()['targets']);
    }

    /**
     * @return array<int, string>
     */
    public static function fixtureCategoryNames(): array
    {
        return array_keys(self::manifest()['fixture_catalog']);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function assertStableFixtureSources(array $manifest): void
    {
        $fixtureCatalog = $manifest['fixture_catalog'] ?? null;

        if (! is_array($fixtureCatalog)) {
            throw new RuntimeException('Platform conformance suite fixture catalog is missing.');
        }

        foreach ($fixtureCatalog as $categoryName => $category) {
            if (! is_array($category) || ($category['status'] ?? null) !== self::CATEGORY_STATUS_STABLE) {
                continue;
            }

            $sources = $category['sources'] ?? null;
            if (! is_array($sources) || $sources === []) {
                throw new RuntimeException(
                    "Stable platform conformance fixture category [{$categoryName}] must declare a source."
                );
            }

            foreach ($sources as $source) {
                if (! is_array($source)) {
                    throw new RuntimeException(
                        "Stable platform conformance fixture category [{$categoryName}] has an invalid source."
                    );
                }

                self::assertStableFixtureSource($categoryName, $source);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function assertStableFixtureSource(string $categoryName, array $source): void
    {
        $artifactId = $source['artifact_id'] ?? null;
        if (! is_string($artifactId) || trim($artifactId) === '') {
            throw new RuntimeException(
                "Stable platform conformance fixture category [{$categoryName}] must declare an artifact identity."
            );
        }

        $resolverUrl = $source['resolver_url'] ?? null;
        if (! is_string($resolverUrl)) {
            throw new RuntimeException("Stable fixture source [{$artifactId}] must declare an immutable resolver URL.");
        }

        $sourcePath = self::localFixtureSourcePath($artifactId, $resolverUrl);
        $declaredDigest = $source['sha256'] ?? null;
        if (! is_string($declaredDigest) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $declaredDigest) !== 1) {
            throw new RuntimeException("Stable fixture source [{$artifactId}] must declare a SHA-256 byte binding.");
        }

        $actualDigest = hash_file('sha256', $sourcePath);
        if ($actualDigest === false || ! hash_equals($declaredDigest, 'sha256:' . $actualDigest)) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] does not match its declared SHA-256 byte binding."
            );
        }
    }

    private static function localFixtureSourcePath(string $artifactId, string $resolverUrl): string
    {
        $url = parse_url($resolverUrl);
        $expectedPrefix = '/durable-workflow/workflow/' . self::FIXTURE_SOURCE_REVISION . '/';

        if (
            ! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ($url['host'] ?? null) !== 'raw.githubusercontent.com'
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port'])
            || isset($url['query'])
            || isset($url['fragment'])
            || ! isset($url['path'])
            || ! is_string($url['path'])
            || ! str_starts_with($url['path'], $expectedPrefix)
        ) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] must use an immutable raw GitHub resolver with a full revision."
            );
        }

        $relativePath = substr($url['path'], strlen($expectedPrefix));
        if (
            preg_match(
                '/\Aresources\/conformance\/suite-v38\/platform-(?:conformance|protocol-specs)\/[a-z0-9.-]+\.(?:json|ya?ml)\z/D',
                $relativePath,
            ) !== 1
        ) {
            throw new RuntimeException(
                "Stable fixture source [{$artifactId}] must resolve to a vendored suite-v38 JSON or YAML byte."
            );
        }

        $sourcePath = dirname(__DIR__, 3) . '/' . $relativePath;
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Vendored stable fixture source [{$artifactId}] is missing.");
        }

        return $sourcePath;
    }
}
