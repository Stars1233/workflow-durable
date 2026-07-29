#!/usr/bin/env python3
"""Behavior and trust contracts for target-branch qualification selection."""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CLASSIFIER = ROOT / "scripts/ci/classify-target-qualification.php"
CONTRACT = ROOT / ".github/target-qualification.json"
BUILD_WORKFLOW = ROOT / ".github/workflows/php.yml"
RECOVERY_WORKFLOW = ROOT / ".github/workflows/release-plan-recovery.yml"
RELEASE_AUDIT_WORKFLOW = ROOT / ".github/workflows/release-docs-audit.yml"
FEATURE_SHARD_VERIFIER = ROOT / "scripts/ci/verify-feature-shards.php"
FEATURE_SHARD_SPLITTER = ROOT / "scripts/ci/split-feature-tests.php"
PR_TEST_SELECTOR = ROOT / "scripts/ci/select-pr-tests.php"


def workflow_job_source(source: str, name: str) -> str:
    marker = f"  {name}:\n"
    if marker not in source:
        raise AssertionError(f"workflow does not define the {name} job")
    job = source.split(marker, 1)[1]
    next_job = re.search(r"(?m)^  [a-z][a-z0-9-]*:\s*$", job)
    return job if next_job is None else job[: next_job.start()]


class QualificationClassificationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.contract = json.loads(CONTRACT.read_text())
        cls.categories = cls.contract["path_categories"]

    def classify(
        self,
        paths: list[str],
        *,
        server_url: str = "https://github.com",
        event_name: str = "push",
        ref: str = "refs/heads/v2",
    ) -> dict[str, str]:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as paths_file:
            paths_file.write("\n".join(paths))
            paths_file.flush()
            result = subprocess.run(
                [
                    "php",
                    str(CLASSIFIER),
                    f"--server-url={server_url}",
                    f"--event-name={event_name}",
                    f"--ref={ref}",
                    f"--paths-file={paths_file.name}",
                    f"--config={CONTRACT}",
                ],
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )

        return dict(line.split("=", 1) for line in result.stdout.splitlines())

    def classify_revisions(self, before: str, head: str) -> dict[str, str]:
        result = subprocess.run(
            [
                "php",
                str(CLASSIFIER),
                "--server-url=https://github.com",
                "--event-name=push",
                "--ref=refs/heads/v2",
                f"--before={before}",
                f"--head={head}",
                f"--config={CONTRACT}",
            ],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

        return dict(line.split("=", 1) for line in result.stdout.splitlines())

    def test_every_allowlisted_path_reports_its_category(self) -> None:
        for category, paths in self.categories.items():
            for path in paths:
                with self.subTest(category=category, path=path):
                    result = self.classify([path])
                    self.assertEqual("release-recovery", result["qualification"])
                    self.assertEqual(category, result["changed_path_categories"])

    def test_runtime_and_qualification_control_paths_select_full(self) -> None:
        paths = {
            "dependency": "composer.json",
            "runtime": "src/V2/Support/LocalActivityRuntime.php",
            "migration": (
                "src/migrations/2022_01_01_000000_create_workflows_table.php"
            ),
            "database": "tests/Feature/DatabaseTruncationTest.php",
            "serialization": "tests/Unit/Serializers/SerializeTest.php",
            "behavior": "tests/Feature/V2/V2WorkflowTest.php",
            "matrix": ".github/feature-test-timings.json",
            "build-workflow": ".github/workflows/php.yml",
            "classification-contract": ".github/target-qualification.json",
            "classifier": "scripts/ci/classify-target-qualification.php",
        }

        for category, path in paths.items():
            with self.subTest(category=category):
                self.assertEqual("full", self.classify([path])["qualification"])

    def test_mixed_change_selects_full(self) -> None:
        focused_path = self.categories["recovery-authority"][0]
        result = self.classify(
            [focused_path, "src/V2/Support/LocalActivityRuntime.php"]
        )
        self.assertEqual("full", result["qualification"])

    def test_empty_or_untrusted_context_selects_full(self) -> None:
        focused_path = self.categories["recovery-authority"][0]
        cases = [
            self.classify([]),
            self.classify([focused_path], event_name="pull_request"),
            self.classify([focused_path], server_url="https://ci.example.test"),
            self.classify([focused_path], ref="refs/heads/feature"),
        ]

        for result in cases:
            self.assertEqual("full", result["qualification"])

    def test_ambiguous_or_unavailable_revision_range_selects_full(self) -> None:
        cases = [
            self.classify_revisions("0" * 40, "a" * 40),
            self.classify_revisions("a" * 40, "b" * 40),
        ]

        for result in cases:
            self.assertEqual("full", result["qualification"])


class FeatureShardQualificationTest(unittest.TestCase):
    known_features = [
        "tests/Feature/SmokeDurableDispatchTest.php",
        "tests/Feature/SmokeSignalUpdateTest.php",
        "tests/Feature/SmokeQueryReplayTest.php",
        "tests/Feature/SmokePersistedPayloadTest.php",
    ]
    unseen_feature = "tests/Feature/V2/UnprofiledFeatureTest.php"

    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)

        for source in (
            BUILD_WORKFLOW,
            ROOT / ".github/workflows/public-boundary.yml",
            FEATURE_SHARD_VERIFIER,
            FEATURE_SHARD_SPLITTER,
            PR_TEST_SELECTOR,
        ):
            relative = source.relative_to(ROOT)
            destination = self.root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)

        smoke_methods = [
            "testDurableDispatch",
            "testSignalUpdateOrder",
            "testQueryReplay",
            "testPersistedPayload",
        ]
        for feature, method in zip(self.known_features, smoke_methods, strict=True):
            self.write(
                feature,
                "<?php\n"
                "final class FeatureFixture\n"
                "{\n"
                f"    public function {method}(): void {{}}\n"
                "}\n",
            )
        self.write(
            self.unseen_feature,
            "<?php\nfinal class UnprofiledFeatureTest {}\n",
        )
        self.write(
            "tests/Unit/QualificationContractTest.php",
            "<?php\n"
            "use PHPUnit\\Framework\\TestCase;\n"
            "final class QualificationContractTest extends TestCase {}\n",
        )

        profile = dict(
            zip(self.known_features, [40.0, 30.0, 20.0, 10.0], strict=True)
        )
        self.write_json(
            ".github/feature-test-timings.json",
            {"mysql": profile, "postgresql": profile},
        )
        self.write_json(
            ".github/pr-test-selection.json",
            {
                "changed_feature_budget_seconds": 120,
                "unknown_feature_weight_seconds": 30,
                "unit_contracts": ["tests/Unit/QualificationContractTest.php"],
                "mysql_smoke": [
                    {
                        "behavior": behavior,
                        "file": feature,
                        "test": method,
                    }
                    for behavior, feature, method in zip(
                        [
                            "durable-dispatch",
                            "signal-update-order",
                            "query-replay",
                            "persisted-payload",
                        ],
                        self.known_features,
                        smoke_methods,
                        strict=True,
                    )
                ],
            },
        )
        self.write("changed-files.txt", f"{self.unseen_feature}\n")

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def write(self, relative: str, contents: str) -> None:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(contents)

    def write_json(self, relative: str, document: object) -> None:
        self.write(relative, json.dumps(document, indent=2) + "\n")

    def run_php(
        self, script: str, *arguments: str, check: bool = True
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["php", script, *arguments],
            cwd=self.root,
            check=check,
            capture_output=True,
            text=True,
        )

    def shard_assignments(self, profile: str) -> list[list[str]]:
        assignments = []

        for shard in range(4):
            result = self.run_php(
                "scripts/ci/split-feature-tests.php",
                "--dir=tests/Feature",
                f"--shard={shard}",
                "--shards=4",
                "--weights=.github/feature-test-timings.json",
                f"--weight-profile={profile}",
            )
            assignments.append(result.stdout.splitlines())

        return assignments

    def test_unprofiled_feature_enters_bounded_and_complete_qualification(
        self,
    ) -> None:
        timing_profile = self.root / ".github/feature-test-timings.json"
        original_profile = timing_profile.read_bytes()

        verification = self.run_php("scripts/ci/verify-feature-shards.php")
        self.assertIn("represented exactly once", verification.stdout)

        for profile in ("mysql", "postgresql"):
            with self.subTest(profile=profile):
                first = self.shard_assignments(profile)
                second = self.shard_assignments(profile)
                represented = [file for shard in first for file in shard]

                self.assertEqual(first, second)
                self.assertCountEqual(
                    self.known_features + [self.unseen_feature],
                    represented,
                )
                self.assertEqual(1, represented.count(self.unseen_feature))
                self.assertEqual(
                    self.unseen_feature,
                    first[3][-1],
                    "known timings should remain authoritative for shard balance",
                )

        selection = self.run_php(
            "scripts/ci/select-pr-tests.php",
            "--config=.github/pr-test-selection.json",
            "--weights=.github/feature-test-timings.json",
            "--weight-profile=mysql",
            "--changed-files=changed-files.txt",
            "--output-dir=pr-selection",
        )
        self.assertEqual("", selection.stdout)
        self.assertEqual(
            f"{self.unseen_feature}\n",
            (self.root / "pr-selection/mysql-changed-files.txt").read_text(),
        )
        self.assertIn(
            f"30.000 (conservative-default) {self.unseen_feature}",
            (self.root / "pr-selection/summary.txt").read_text(),
        )
        self.assertEqual(original_profile, timing_profile.read_bytes())

    def test_profile_entries_must_describe_discovered_tests(self) -> None:
        profile_path = ".github/feature-test-timings.json"
        document = json.loads((self.root / profile_path).read_text())
        document["mysql"]["tests/Feature/ExplicitlyExcludedTest.php"] = 5
        self.write_json(profile_path, document)

        result = self.run_php(
            "scripts/ci/verify-feature-shards.php",
            check=False,
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "contains tests outside the discovered feature inventory",
            result.stderr,
        )

    def test_malformed_profile_still_fails_qualification(self) -> None:
        profile_path = ".github/feature-test-timings.json"
        document = json.loads((self.root / profile_path).read_text())
        document["mysql"][self.known_features[0]] = -1
        self.write_json(profile_path, document)

        result = self.run_php(
            "scripts/ci/verify-feature-shards.php",
            check=False,
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("must be greater than zero", result.stderr)

    def test_duplicate_or_unassigned_features_still_fail_qualification(
        self,
    ) -> None:
        cases = {
            "duplicate": (
                [
                    self.known_features[0],
                    self.known_features[0],
                    self.known_features[1],
                    self.known_features[2],
                ],
                f"Duplicated: [{self.known_features[0]}]",
            ),
            "unassigned": (
                self.known_features,
                f"Missing: [{self.unseen_feature}]",
            ),
        }

        for name, (assignments, expected_error) in cases.items():
            with self.subTest(name=name):
                assignment_literal = ",\n    ".join(
                    f"'{assignment}'" for assignment in assignments
                )
                self.write(
                    "scripts/ci/split-feature-tests.php",
                    "<?php\n"
                    "$options = getopt('', [\n"
                    "    'dir:',\n"
                    "    'shard:',\n"
                    "    'shards:',\n"
                    "    'weights:',\n"
                    "    'weight-profile:',\n"
                    "]);\n"
                    "$assignments = [\n"
                    f"    {assignment_literal},\n"
                    "];\n"
                    "echo $assignments[(int) $options['shard']] . PHP_EOL;\n",
                )

                result = self.run_php(
                    "scripts/ci/verify-feature-shards.php",
                    check=False,
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(expected_error, result.stderr)


class QualificationWorkflowTrustTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.build = BUILD_WORKFLOW.read_text()
        cls.recovery = RECOVERY_WORKFLOW.read_text()
        cls.release_audit = RELEASE_AUDIT_WORKFLOW.read_text()
        cls.workflow_sources = [
            path.read_text() for path in (ROOT / ".github/workflows").glob("*.yml")
        ]

    def test_build_has_no_release_credentials_and_does_not_persist_checkout_token(
        self,
    ) -> None:
        self.assertIn("\npermissions:\n  contents: read\n", self.build)
        self.assertNotIn("${{ secrets.", self.build)
        all_workflows = "\n".join(self.workflow_sources)
        checkout_count = all_workflows.count("uses: actions/checkout@")
        self.assertGreater(checkout_count, 0)
        self.assertEqual(
            checkout_count,
            all_workflows.count("persist-credentials: false"),
        )

    def test_pull_request_caches_are_separate_from_protected_run_caches(self) -> None:
        cache_count = self.build.count("uses: actions/cache@")
        self.assertGreater(cache_count, 0)
        self.assertEqual(
            cache_count,
            self.build.count("key: ${{ github.event_name }}-${{ runner.os }}-php-"),
        )
        self.assertEqual(
            cache_count,
            self.build.count("${{ github.event_name }}-${{ runner.os }}-php-") // 2,
        )

    def test_protected_workflows_cannot_be_triggered_by_pull_requests(self) -> None:
        for source in (self.recovery, self.release_audit):
            trigger = source.split("\npermissions:", 1)[0]
            self.assertNotIn("pull_request", trigger)

        self.assertIn("if: github.ref == 'refs/heads/v2'", self.recovery)

    def test_recovery_discovery_has_no_publication_authority(self) -> None:
        discover = workflow_job_source(self.recovery, "discover")
        self.assertIn("contents: read", discover)
        self.assertNotIn("contents: write", discover)
        self.assertNotIn("environment:", discover)
        self.assertIn("component-release-recovery.py", discover)
        self.assertIn(
            "action: ${{ steps.recovery.outputs.action }}",
            discover,
        )

    def test_recovery_publication_authority_requires_the_immutable_publish_decision(
        self,
    ) -> None:
        publish = workflow_job_source(self.recovery, "publish")
        self.assertIn("needs: discover", publish)
        self.assertIn(
            "needs.discover.outputs.action == 'publish'",
            publish,
        )
        self.assertIn("environment: packagist", publish)
        self.assertIn("contents: write", publish)
        self.assertNotIn('component-release-recovery.py "${arguments[@]}"', publish)
        self.assertEqual(1, self.recovery.count("environment: packagist"))
        self.assertEqual(1, self.recovery.count("contents: write"))

    def test_recovery_publication_consumes_only_the_exact_same_run_handoff(
        self,
    ) -> None:
        discover = workflow_job_source(self.recovery, "discover")
        publish = workflow_job_source(self.recovery, "publish")
        for output in (
            "artifact-digest: ${{ steps.privileged-handoff.outputs.artifact-digest }}",
            "artifact-id: ${{ steps.privileged-handoff.outputs.artifact-id }}",
            "source-run-attempt: ${{ github.run_attempt }}",
            "source-run-id: ${{ github.run_id }}",
        ):
            self.assertIn(output, discover)
        for binding in (
            "artifact-ids: ${{ needs.discover.outputs.artifact-id }}",
            "digest-mismatch: error",
            "github-token: ${{ github.token }}",
            "repository: ${{ github.repository }}",
            "run-id: ${{ needs.discover.outputs.source-run-id }}",
        ):
            self.assertIn(binding, publish)
        self.assertIn("EXPECTED_ARTIFACT_DIGEST:", publish)
        self.assertIn("EXPECTED_SOURCE_RUN_ATTEMPT:", publish)
        self.assertIn("EXPECTED_SOURCE_RUN_ID:", publish)

    def test_broad_jobs_require_the_full_class(self) -> None:
        for job in (
            "quality",
            "feature-mysql",
            "feature-mariadb",
            "feature-postgresql",
            "coverage",
        ):
            source = self.job_source(job)
            self.assertIn("needs.route.outputs.qualification == 'full'", source)

    def test_required_check_has_explicit_full_and_focused_contracts(self) -> None:
        build_job = self.job_source("build")
        self.assertIn("qualification == 'release-recovery'", build_job)
        self.assertIn("qualification == 'full'", build_job)
        self.assertIn("Report qualification class and elapsed time", build_job)

    def job_source(self, name: str) -> str:
        return workflow_job_source(self.build, name)


if __name__ == "__main__":
    unittest.main()
