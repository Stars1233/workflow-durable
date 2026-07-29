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
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/base.json",
            self.codec_fixture("base-codec-case", "0", "AA=="),
        )
        self.write_json(
            "tests/Evidence/existing-codec.json",
            self.codec_fixture("existing-unselected-codec-case", "1", "Ag=="),
        )
        policy = json.loads(
            (REPOSITORY_ROOT / "regression-corpus-policy.json").read_text(encoding="utf-8")
        )
        self.write_json("regression-corpus-policy.json", policy)
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
    def codec_fixture(identity: str, value: str, wire_base64: str) -> dict[str, Any]:
        return {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.codec-regression/v1",
            "id": identity,
            "protocol": {
                "codec": "avro",
                "schema": "example.Value",
                "version": "1",
                "fingerprint": None,
            },
            "bindings": ["php"],
            "value": {"type": "long", "value": value},
            "framing": {"encoding": "base64", "wire_base64": wire_base64},
            "failure_policy": {"operation": "round_trip", "error": None},
        }

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
            "codec implementation changed but corpus growth has no newly added fixture evidence",
            result.stderr,
        )

    def test_codec_change_accepts_new_fixture_evidence(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/new.json",
            self.codec_fixture("new-codec-case", "2", "BA=="),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])
        self.assertEqual(1, counts["new_fixture_evidence"])
        self.assertTrue(counts["related_change"])

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


if __name__ == "__main__":
    unittest.main()
