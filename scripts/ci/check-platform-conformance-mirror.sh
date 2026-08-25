#!/usr/bin/env sh

set -eu

authority_url="${PLATFORM_CONFORMANCE_AUTHORITY_URL:-https://durable-workflow.com/platform-conformance-contract.json}"
version="${WORKFLOW_PLATFORM_CONFORMANCE_VERSION:-${GITHUB_REF_NAME:-}}"
mirror_file="${WORKFLOW_PLATFORM_CONFORMANCE_MIRROR_FILE:-}"
attempts="${PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS:-6}"
sleep_seconds="${PLATFORM_CONFORMANCE_AUTHORITY_RETRY_SLEEP:-20}"
repo_root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
tmp_dir="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/workflow-platform-conformance.XXXXXX")"
authority_file="$tmp_dir/public-authority.json"
workflow_file="$tmp_dir/workflow-mirror.json"
history_binding_file="$tmp_dir/history-export-binding.json"
history_source_file="$tmp_dir/history-export-source.json"
history_resolver_file="$tmp_dir/history-export-resolver.json"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

version="${version#v}"

case "$attempts" in
    ''|*[!0-9]*) echo "PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS must be a positive integer." >&2; exit 2 ;;
esac

download_with_retry() {
    download_url="$1"
    download_file="$2"
    download_label="$3"
    attempt=1

    while [ "$attempt" -le "$attempts" ]; do
        if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 \
            -o "$download_file" "$download_url"; then
            return
        fi

        if [ "$attempt" -eq "$attempts" ]; then
            echo "Unable to retrieve $download_label from $download_url." >&2
            exit 1
        fi

        sleep "$sleep_seconds"
        attempt=$((attempt + 1))
    done
}

download_with_retry "$authority_url" "$authority_file" "public platform conformance authority"

if [ -n "$mirror_file" ]; then
    cp "$mirror_file" "$workflow_file"
else
    if [ -z "$version" ]; then
        echo "WORKFLOW_PLATFORM_CONFORMANCE_VERSION or GITHUB_REF_NAME must name the published Workflow prerelease." >&2
        exit 2
    fi

    composer --working-dir="$tmp_dir" init --name=durable-workflow/conformance-release-audit --no-interaction
    composer --working-dir="$tmp_dir" require --no-interaction --no-progress --prefer-dist \
        "durable-workflow/workflow:$version"

    WORKFLOW_AUDIT_AUTOLOAD="$tmp_dir/vendor/autoload.php" \
    WORKFLOW_AUDIT_EXPECTED_VERSION="$version" \
    WORKFLOW_AUDIT_OUTPUT="$workflow_file" \
    WORKFLOW_AUDIT_HISTORY_BINDING_OUTPUT="$history_binding_file" \
    WORKFLOW_AUDIT_HISTORY_SOURCE_OUTPUT="$history_source_file" \
    php -r '
        require getenv("WORKFLOW_AUDIT_AUTOLOAD");
        $expected = getenv("WORKFLOW_AUDIT_EXPECTED_VERSION");
        $installed = Composer\InstalledVersions::getPrettyVersion("durable-workflow/workflow")
            ?: Composer\InstalledVersions::getVersion("durable-workflow/workflow");
        if ($installed !== $expected) {
            fwrite(STDERR, "Installed Workflow version {$installed} does not match {$expected}.\n");
            exit(1);
        }
        if (Workflow\V2\Support\PlatformConformanceSuite::workflowSourceRelease() !== $expected) {
            fwrite(STDERR, "Workflow source release identity does not match {$expected}.\n");
            exit(1);
        }
        $manifest = Workflow\V2\Support\PlatformConformanceSuite::manifest();
        file_put_contents(
            getenv("WORKFLOW_AUDIT_OUTPUT"),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $dependency = $manifest["source_dependencies"]["history-export-bundle.schema.json"] ?? null;
        if (!is_array($dependency)) {
            fwrite(STDERR, "Published Workflow package does not bind the history-export schema.\n");
            exit(1);
        }
        file_put_contents(
            getenv("WORKFLOW_AUDIT_HISTORY_BINDING_OUTPUT"),
            json_encode($dependency, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $installPath = Composer\InstalledVersions::getInstallPath("durable-workflow/workflow");
        $sourcePath = $dependency["source_path"] ?? null;
        if (!is_string($installPath) || !is_string($sourcePath)) {
            fwrite(STDERR, "Published Workflow history-export source path is unavailable.\n");
            exit(1);
        }
        $source = $installPath."/".$sourcePath;
        if (!is_file($source) || !copy($source, getenv("WORKFLOW_AUDIT_HISTORY_SOURCE_OUTPUT"))) {
            fwrite(STDERR, "Unable to retain the packaged history-export schema for release audit.\n");
            exit(1);
        }
    '

    history_resolver_url="$(WORKFLOW_AUDIT_HISTORY_BINDING="$history_binding_file" php -r '
        $binding = json_decode(file_get_contents(getenv("WORKFLOW_AUDIT_HISTORY_BINDING")), true, 512, JSON_THROW_ON_ERROR);
        if (!is_string($binding["resolver_url"] ?? null)) {
            fwrite(STDERR, "History-export schema resolver is missing.\n");
            exit(1);
        }
        echo $binding["resolver_url"];
    ')"
    download_with_retry "$history_resolver_url" "$history_resolver_file" \
        "published history-export schema resolver"

    WORKFLOW_AUDIT_HISTORY_BINDING="$history_binding_file" \
    WORKFLOW_AUDIT_HISTORY_SOURCE="$history_source_file" \
    WORKFLOW_AUDIT_HISTORY_RESOLVER="$history_resolver_file" \
    php -r '
        $binding = json_decode(file_get_contents(getenv("WORKFLOW_AUDIT_HISTORY_BINDING")), true, 512, JSON_THROW_ON_ERROR);
        $sourceRelease = $binding["source_release"] ?? null;
        $sourcePath = $binding["source_path"] ?? null;
        if (!is_string($sourceRelease) || preg_match("/\\A2\\.0\\.0-rc\\.[1-9][0-9]*\\z/D", $sourceRelease) !== 1) {
            fwrite(STDERR, "History-export schema retained source release is invalid.\n");
            exit(1);
        }
        if (!is_string($sourcePath)) {
            fwrite(STDERR, "History-export schema packaged source path is invalid.\n");
            exit(1);
        }
        $expectedArtifact = "durable-workflow.v2.history-export-bundle@workflow-{$sourceRelease}-schema-2";
        if (($binding["artifact_id"] ?? null) !== $expectedArtifact) {
            fwrite(STDERR, "History-export artifact identity does not derive from its retained source release.\n");
            exit(1);
        }
        $expectedResolver = "https://raw.githubusercontent.com/durable-workflow/workflow/{$sourceRelease}/{$sourcePath}";
        if (($binding["resolver_url"] ?? null) !== $expectedResolver) {
            fwrite(STDERR, "History-export resolver does not use its retained source release.\n");
            exit(1);
        }
        $declared = $binding["sha256"] ?? null;
        $source = getenv("WORKFLOW_AUDIT_HISTORY_SOURCE");
        $resolver = getenv("WORKFLOW_AUDIT_HISTORY_RESOLVER");
        $sourceDigest = hash_file("sha256", $source);
        $resolverDigest = hash_file("sha256", $resolver);
        if (!is_string($declared) || !is_string($sourceDigest) || !is_string($resolverDigest)
            || !hash_equals($declared, "sha256:".$sourceDigest)
            || !hash_equals($sourceDigest, $resolverDigest)
            || file_get_contents($source) !== file_get_contents($resolver)
        ) {
            fwrite(STDERR, "Published history-export resolver bytes do not match the packaged source binding.\n");
            exit(1);
        }
    '
fi

php "$repo_root/scripts/ci/compare-platform-conformance-mirrors.php" "$workflow_file" "$authority_file"
