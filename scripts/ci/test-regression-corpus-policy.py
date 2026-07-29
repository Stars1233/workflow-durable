#!/usr/bin/env python3
"""Adversarial checks for regression-corpus policy enforcement."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
VALIDATOR = Path(__file__).with_name("validate-regression-corpus.py")
PHP_GOLDEN_REPLAY_WORKFLOW = "Tests\\Fixtures\\V2\\TestGoldenReplayWorkflow"


def run(*arguments: str, cwd: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        list(arguments),
        cwd=cwd,
        check=False,
        capture_output=True,
        text=True,
    )


class RegressionCorpusPolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        for source_path in self.codec_source_paths():
            target = self.root / source_path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")
        for source_path in self.replay_source_paths():
            target = self.root / source_path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/base.json",
            self.codec_fixture("base-codec-case", "0", "AA=="),
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.replay_fixture("base-replay-case"),
        )
        self.write_json(
            "tests/Evidence/existing-codec.json",
            self.codec_fixture("existing-unselected-codec-case", "1", "Ag=="),
        )
        policy = json.loads(
            (REPOSITORY_ROOT / "regression-corpus-policy.json").read_text(encoding="utf-8")
        )
        self.write_json("regression-corpus-policy.json", policy)
        consumer = self.root / "vendor/bin/phpunit"
        consumer.parent.mkdir(parents=True, exist_ok=True)
        consumer.write_text(
            """<?php
$arguments = implode(' ', $argv);
$codec = str_contains($arguments, 'AvroValueProtocolTest.php');
$fixtureDirectory = $codec
    ? __DIR__ . '/../../tests/Fixtures/V2/CodecRegression'
    : __DIR__ . '/../../tests/Fixtures/V2/ReplayRegression';
$source = $codec
    ? __DIR__ . '/../../src/Serializers/Json.php'
    : __DIR__ . '/../../src/V2/Support/WorkflowFiberRunner.php';
foreach (glob($fixtureDirectory . '/*.json') ?: [] as $path) {
    $fixture = json_decode((string) file_get_contents($path), true);
    if (
        str_contains((string) ($fixture['id'] ?? ''), 'requires-change')
        && str_contains((string) file_get_contents($source), "'base'")
    ) {
        fwrite(STDERR, "fixture requires the guarded implementation change\\n");
        exit(1);
    }
}
exit(0);
""",
            encoding="utf-8",
        )
        self.git("init", "--quiet")
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=baseline",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def tearDown(self) -> None:
        self.temporary.cleanup()

    @staticmethod
    def codec_source_paths() -> tuple[str, ...]:
        return (
            "src/Serializers/Json.php",
            "src/Serializers/Serializer.php",
            "src/V2/Support/PayloadEnvelopeResolver.php",
            "src/V2/Support/WorkflowPayloadDecoder.php",
        )

    @staticmethod
    def replay_source_paths() -> tuple[str, ...]:
        return (
            "src/V2/Support/DefaultWorkflowTaskBridge.php",
            "src/V2/Support/QueryStateReplayer.php",
            "src/V2/Support/WorkflowExecution.php",
            "src/V2/Support/WorkflowExecutor.php",
            "src/V2/Support/WorkflowFiberRunner.php",
            "src/V2/Support/WorkflowReplayer.php",
            "src/V2/Support/WorkflowStepHistory.php",
        )

    @staticmethod
    def codec_fixture(
        identity: str,
        value: str,
        wire_base64: str,
        *,
        codec: str = "avro",
        schema: str = "example.Value",
        fingerprint: str | None = None,
        tagged_value: dict[str, Any] | None = None,
        bindings: list[str] | None = None,
    ) -> dict[str, Any]:
        return {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.codec-regression/v1",
            "id": identity,
            "protocol": {
                "codec": codec,
                "schema": schema,
                "version": "1",
                "fingerprint": fingerprint,
            },
            "bindings": bindings or ["php"],
            "value": tagged_value or {"type": "long", "value": value},
            "framing": {"encoding": "base64", "wire_base64": wire_base64},
            "failure_policy": {"operation": "round_trip", "error": None},
        }

    @staticmethod
    def replay_fixture(
        identity: str,
        *,
        bindings: list[str] | None = None,
        protocol_version: str = "2",
        workflow_type: str = "Tests\\Fixtures\\ReplayWorkflow",
    ) -> dict[str, Any]:
        return {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.replay-regression/v1",
            "id": identity,
            "protocol_version": protocol_version,
            "bindings": bindings or ["php"],
            "workflow": {
                "type": workflow_type,
                "arguments": [],
                "payload_codec": "json",
            },
            "history": [
                {
                    "event_type": "WorkflowStarted",
                    "payload": {},
                }
            ],
            "expected": {
                "completed": True,
                "result": "done",
                "commands": [],
            },
        }

    @staticmethod
    def avro_golden_fixture(
        wire_base64: str,
        *,
        kind: str = "long",
        value: Any = "7",
        value_base64: str | None = None,
    ) -> dict[str, Any]:
        case = {
            "name": f"{kind}_value",
            "kind": kind,
            "wire_base64": wire_base64,
        }
        if value is not None:
            case["value"] = value
        if value_base64 is not None:
            case["value_base64"] = value_base64
        return {
            "schema": "example.CrossFormatValue",
            "fingerprint": "0123456789abcdef",
            "cases": [case],
            "alternate_map_orders": [
                {
                    "name": "map_order",
                    "wire_base64": ["Ag==", "Aw=="],
                }
            ],
            "malformed_frames": [
                {
                    "name": "bad_frame",
                    "error": "invalid_payload_framing",
                    "wire_base64": "AQ==",
                }
            ],
        }

    @classmethod
    def golden_history_fixture(cls) -> dict[str, Any]:
        replay = cls.official_history_replay_fixture()
        history = json.loads(json.dumps(replay["history"]))
        history[1]["payload"]["result_value"] = "Hello, Ada!"
        del history[1]["payload"]["result"]
        del history[1]["payload"]["payload_codec"]
        for event in history:
            event.pop("sequence")
            event.pop("recorded_at")
        return {
            "fixture_schema": "durable-workflow.golden-history.v1",
            "source": {
                "runtime": "workflow-php",
                "package": "durable-workflow/workflow",
                "version": "2.0.0",
                "worker_protocol_version": "1.0",
            },
            "cases": [
                {
                    "name": "base-replay",
                    "family": "activity",
                    "scenario": replay["workflow"]["arguments"][0],
                    "history": history,
                    "expected_state": replay["expected"]["result"],
                }
            ],
        }

    @classmethod
    def official_history_replay_fixture(cls) -> dict[str, Any]:
        replay = cls.replay_fixture(
            "official-history-replay",
            workflow_type=PHP_GOLDEN_REPLAY_WORKFLOW,
        )
        replay["workflow"]["arguments"] = ["single-activity"]
        replay["history"] = [
            {
                "sequence": 1,
                "event_type": "WorkflowStarted",
                "payload": {},
                "recorded_at": "2026-07-29T12:00:00+00:00",
            },
            {
                "sequence": 7,
                "event_type": "ActivityCompleted",
                "payload": {
                    "sequence": 7,
                    "activity_type": "Tests\\Fixtures\\V2\\TestGreetingActivity",
                    "result": '"Hello, Ada!"',
                    "payload_codec": "json",
                },
                "recorded_at": "2026-07-29T12:00:01+00:00",
            },
        ]
        replay["expected"] = {
            "completed": True,
            "result": {
                "stage": "completed",
                "name": None,
                "greeting": "Hello, Ada!",
                "approved": False,
                "version": -1,
                "version_result": None,
                "reservation_id": None,
                "events": ["activity:Hello, Ada!"],
            },
            "commands": [{"type": "complete_workflow"}],
        }
        return replay

    def git(self, *arguments: str) -> subprocess.CompletedProcess[str]:
        result = run("git", *arguments, cwd=self.root)
        if result.returncode != 0:
            self.fail(
                f"git command failed: {arguments!r}\n{result.stdout}\n{result.stderr}"
            )
        return result

    def read_policy(self) -> dict[str, Any]:
        return json.loads(
            (self.root / "regression-corpus-policy.json").read_text(encoding="utf-8")
        )

    def write_json(self, relative_path: str, value: dict[str, Any]) -> None:
        target = self.root / relative_path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")

    def validate(self) -> subprocess.CompletedProcess[str]:
        return run(
            sys.executable,
            str(VALIDATOR),
            "--root",
            str(self.root),
            "--base-ref",
            self.base_ref,
            cwd=self.root,
        )

    def commit_current_as_base(self) -> None:
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=expanded-baseline",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def test_fixture_deletion_cannot_hide_behind_weakened_inventory(self) -> None:
        (self.root / "tests/Fixtures/V2/CodecRegression/base.json").unlink()
        policy = self.read_policy()
        policy["categories"]["codec"]["fixtures"] = [
            policy["categories"]["codec"]["fixtures"][0]
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.fixtures cannot remove or change a base selector",
            result.stderr,
        )

    def test_codec_change_cannot_hide_behind_weakened_guard(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["codec"]["guards"] = [
            guard
            for guard in policy["categories"]["codec"]["guards"]
            if guard["glob"] != "src/Serializers/*.php"
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.guards cannot remove or change a base selector",
            result.stderr,
        )

    def test_selector_expansion_cannot_manufacture_growth_from_existing_json(
        self,
    ) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": "tests/Evidence/existing-codec.json",
                "format": "codec-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "is not bound to this repository's official php consumer",
            result.stderr,
        )

    def test_codec_change_accepts_minimal_counterfactual_fixture(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/new.json",
            self.codec_fixture("requires-change-codec-case", "2", "BA=="),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])
        self.assertEqual(1, counts["new_fixture_evidence"])
        self.assertEqual(1, counts["counterfactual_fixture_paths"])
        self.assertTrue(counts["related_change"])

    def test_already_passing_fixture_cannot_prove_guarded_regression(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/unrelated.json",
            self.codec_fixture("unrelated-already-passing-case", "2", "BA=="),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "passes the official php codec-regression-v1 consumer at both base "
            "and current revisions",
            result.stderr,
        )

    def test_replay_selector_cannot_escape_the_official_consumer(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["replay"]["fixtures"].append(
            {
                "glob": "tests/Evidence/*.json",
                "format": "replay-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)
        self.write_json(
            "tests/Evidence/new-replay.json",
            self.replay_fixture(
                "inert-replay-case",
                workflow_type="NoSuch\\Workflow",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "is not bound to this repository's official php consumer",
            result.stderr,
        )

    def test_codec_binding_metadata_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/metadata-rewrap.json",
            self.codec_fixture(
                "metadata-rewrapped-codec-case",
                "0",
                "AA==",
                bindings=["rust", "php"],
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_replay_binding_metadata_cannot_manufacture_growth(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/metadata-rewrap.json",
            self.replay_fixture(
                "metadata-rewrapped-replay-case",
                bindings=["rust", "php"],
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_replay_protocol_version_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/version-relabel.json",
            self.replay_fixture(
                "version-relabeled-replay-case",
                protocol_version="999",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_golden_history_rewrap_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/rewrapped.json",
            self.golden_history_fixture(),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_genuinely_new_replay_behavior_grows_the_corpus(self) -> None:
        fixture = self.replay_fixture(
            "new-replay-behavior",
            workflow_type="Tests\\Fixtures\\AnotherReplayWorkflow",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/new.json",
            fixture,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_codec_schema_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/schema-relabel.json",
            self.codec_fixture(
                "schema-relabeled-codec-case",
                "0",
                "AA==",
                schema="example.RelabeledValue",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_codec_name_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/codec-relabel.json",
            self.codec_fixture(
                "codec-relabeled-codec-case",
                "0",
                "AA==",
                codec="renamed-avro",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_cross_format_rewrapping_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA=="),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-long-seven",
                "7",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_equivalent_base64_bytes_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA=="),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "equivalent-base64-long-seven",
                "7",
                "AB==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_bytes_value_aliases_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture(
                "AA==",
                kind="bytes",
                value=None,
                value_base64="AP8=",
            ),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-bytes",
                "",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "bytes", "base64": "AP8="},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_equivalent_base64_value_bytes_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture(
                "AA==",
                kind="bytes",
                value=None,
                value_base64="AP8=",
            ),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "equivalent-base64-bytes",
                "",
                "AB==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "bytes", "base64": "AP9="},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_malformed_golden_wire_must_be_canonical_base64(self) -> None:
        fixture = self.avro_golden_fixture("AA==")
        fixture["malformed_frames"][0]["wire_base64"] = "%%%"
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            fixture,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_double_representations_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA==", kind="double", value=7.0),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-double",
                "",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "double", "value": "7.0"},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_active_payload_codec_surfaces_require_corpus_growth(self) -> None:
        for source_path in self.codec_source_paths():
            with self.subTest(source_path=source_path):
                target = self.root / source_path
                target.write_text("<?php\nreturn 'changed';\n", encoding="utf-8")

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn(
                    "codec implementation changed but its corpus did not grow",
                    result.stderr,
                )
                target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")

    def test_core_replay_surfaces_require_growth_without_diff_keywords(self) -> None:
        for source_path in self.replay_source_paths():
            with self.subTest(source_path=source_path):
                target = self.root / source_path
                target.write_text("<?php\nreturn 'changed';\n", encoding="utf-8")

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn(
                    "replay implementation changed but its corpus did not grow",
                    result.stderr,
                )
                target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")

    def test_workflow_step_history_guard_cannot_be_weakened(self) -> None:
        policy = self.read_policy()
        policy["categories"]["replay"]["guards"] = [
            guard
            for guard in policy["categories"]["replay"]["guards"]
            if guard["glob"] != "src/V2/Support/WorkflowStepHistory.php"
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.replay.guards cannot remove or change a base selector",
            result.stderr,
        )


if __name__ == "__main__":
    unittest.main()
