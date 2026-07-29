#!/usr/bin/env python3
"""Validate immutable replay and payload-codec regression evidence."""

from __future__ import annotations

import argparse
import base64
import binascii
import fnmatch
import hashlib
import json
import math
import os
import re
import shutil
import subprocess
import sys
import tempfile
from collections import Counter
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

POLICY_SCHEMA = "durable-workflow.regression-corpus-policy/v1"
CODEC_SCHEMA = "durable-workflow.codec-regression/v1"
REPLAY_SCHEMA = "durable-workflow.replay-regression/v1"
GOLDEN_HISTORY_SCHEMA = "durable-workflow.golden-history.v1"
PHP_GOLDEN_REPLAY_WORKFLOW = "Tests\\Fixtures\\V2\\TestGoldenReplayWorkflow"
PHP_GOLDEN_HISTORY_FAMILIES = {
    "activity",
    "saga-compensation",
    "signal-update",
    "version-marker",
    "wait-condition",
}
SUPPORTED_FORMATS = {
    "avro-value-golden-v1",
    "codec-regression-v1",
    "golden-history-v1",
    "replay-regression-v1",
}
SUPPORTED_CATEGORIES = {"codec", "replay"}
SUPPORTED_BINDINGS = {"php", "python", "rust"}
OFFICIAL_BINDING_FIXTURE_SELECTORS = {
    "php": {
        (
            "codec",
            "resources/protocol/avro-value-v1-golden.json",
            "avro-value-golden-v1",
        ),
        (
            "codec",
            "tests/Fixtures/V2/CodecRegression/*.json",
            "codec-regression-v1",
        ),
        (
            "replay",
            "tests/Fixtures/V2/GoldenHistory/*.json",
            "golden-history-v1",
        ),
        (
            "replay",
            "tests/Fixtures/V2/ReplayRegression/*.json",
            "replay-regression-v1",
        ),
    },
}
OFFICIAL_BINDING_CONSUMERS = {
    (
        "php",
        "codec",
        "resources/protocol/avro-value-v1-golden.json",
        "avro-value-golden-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--filter",
        "testCanonicalSchemaFingerprintAndGoldenBytes|"
        "testSharedTrailingBytesFrameIsRejected|"
        "testSharedAlternateMapOrdersDecodeToTheSameNestedValue",
        "tests/Unit/Serializers/AvroValueProtocolTest.php",
    ),
    (
        "php",
        "codec",
        "tests/Fixtures/V2/CodecRegression/*.json",
        "codec-regression-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--filter",
        "testCheckedInCodecRegressionCorpusUsesTheOfficialBinding",
        "tests/Unit/Serializers/AvroValueProtocolTest.php",
    ),
    (
        "php",
        "replay",
        "tests/Fixtures/V2/GoldenHistory/*.json",
        "golden-history-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--testsuite",
        "feature",
        "--filter",
        "testPhpGoldenHistoryReplayContract",
        "tests/Feature/V2/V2GoldenHistoryReplayTest.php",
    ),
    (
        "php",
        "replay",
        "tests/Fixtures/V2/ReplayRegression/*.json",
        "replay-regression-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "tests/Unit/V2/ReplayRegressionCorpusTest.php",
    ),
}
RUNTIME_DEPENDENCY_PATHS = ("vendor",)
ZERO_COMMIT = re.compile(r"^0+$")


class CorpusError(RuntimeError):
    """The regression-corpus contract is not satisfied."""


@dataclass(frozen=True)
class Evidence:
    category: str
    identity: str
    path: str
    protocol_version: str
    semantic_digest: str
    supersedes: tuple[str, ...] = ()


def _canonical_digest(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest()


def _object(value: Any, context: str) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise CorpusError(f"{context} must be an object")
    return value


def _list(value: Any, context: str, *, nonempty: bool = False) -> Sequence[Any]:
    if not isinstance(value, Sequence) or isinstance(value, str | bytes):
        raise CorpusError(f"{context} must be an array")
    if nonempty and not value:
        raise CorpusError(f"{context} must not be empty")
    return value


def _string(value: Any, context: str) -> str:
    if not isinstance(value, str) or not value:
        raise CorpusError(f"{context} must be a non-empty string")
    return value


def _boolean(value: Any, context: str) -> bool:
    if not isinstance(value, bool):
        raise CorpusError(f"{context} must be a boolean")
    return value


def _nullable_string(value: Any, context: str) -> str | None:
    if value is None:
        return None
    return _string(value, context)


def _unique_strings(value: Any, context: str, *, allowed: set[str] | None = None) -> tuple[str, ...]:
    values = tuple(_string(item, f"{context}[]") for item in _list(value, context, nonempty=True))
    if len(values) != len(set(values)):
        raise CorpusError(f"{context} contains duplicates")
    if allowed is not None and not set(values) <= allowed:
        raise CorpusError(f"{context} contains unsupported values: {sorted(set(values) - allowed)}")
    return values


def _json(content: bytes, path: str) -> Mapping[str, Any]:
    try:
        value = json.loads(content)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise CorpusError(f"{path} is not valid UTF-8 JSON: {error}") from error
    return _object(value, path)


def _canonical_base64(
    value: str,
    context: str,
) -> str:
    try:
        decoded = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError) as error:
        raise CorpusError(f"{context} is not canonical base64") from error
    canonical = base64.b64encode(decoded).decode("ascii")
    if value != canonical:
        raise CorpusError(f"{context} is not canonical base64")
    return canonical


def _canonical_wire_migration(base_content: bytes, current_content: bytes) -> bool:
    """Allow the one-way repair of legacy malformed-frame wire spellings."""

    try:
        base_document = json.loads(base_content)
        current_document = json.loads(current_content)
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False
    if not isinstance(base_document, dict) or not isinstance(current_document, dict):
        return False
    base_frames = base_document.get("malformed_frames")
    current_frames = current_document.get("malformed_frames")
    if not isinstance(base_frames, list) or not isinstance(current_frames, list):
        return False
    if len(base_frames) != len(current_frames):
        return False

    migrated = False
    for index, (base_frame, current_frame) in enumerate(
        zip(base_frames, current_frames, strict=True)
    ):
        if not isinstance(base_frame, dict) or not isinstance(current_frame, dict):
            return False
        base_wire = base_frame.get("wire_base64")
        current_wire = current_frame.get("wire_base64")
        if base_wire == current_wire:
            continue
        if not isinstance(base_wire, str) or not isinstance(current_wire, str):
            return False
        try:
            _canonical_base64(base_wire, f"base.malformed_frames[{index}].wire_base64")
        except CorpusError:
            pass
        else:
            return False
        try:
            _canonical_base64(
                current_wire,
                f"current.malformed_frames[{index}].wire_base64",
            )
        except CorpusError:
            return False
        base_frame["wire_base64"] = current_wire
        migrated = True

    return migrated and base_document == current_document


def _replay_semantic(
    *,
    workflow_type: str,
    workflow_input: Any,
    history: Any,
    command_sequence: Any,
    expected: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Project every replay representation onto consumer-executed values."""

    return {
        "workflow": {"type": workflow_type, "input": workflow_input},
        "history": history,
        "command_sequence": command_sequence,
        "expected": expected,
    }


def _consumer_payload(
    value: Mapping[str, Any],
    context: str,
    *,
    default_codec: str,
    golden_values: bool,
) -> Mapping[str, Any]:
    """Return the payload values that reach the replayed workflow."""

    payload = dict(value)
    decoded_fields: set[str] = set()
    codec = payload.get("payload_codec", default_codec)
    for field in ("result", "value", "arguments"):
        value_field = f"{field}_value"
        if golden_values and value_field in payload:
            payload[field] = payload.pop(value_field)
            decoded_fields.add(field)
            continue
        if (
            field not in payload
            or codec != "json"
            or not isinstance(payload[field], str)
        ):
            continue
        try:
            payload[field] = json.loads(payload[field])
        except json.JSONDecodeError as error:
            raise CorpusError(
                f"{context}.{field} is not valid json payload data"
            ) from error
        decoded_fields.add(field)

    if decoded_fields:
        payload.pop("payload_codec", None)
    return payload


def _consumer_sequence(
    event: Mapping[str, Any],
    payload: Mapping[str, Any],
    *,
    golden_position: int | None,
) -> int | None:
    """Resolve sequence aliases in the same precedence order as the consumers."""

    for value in (
        payload.get("sequence"),
        payload.get("workflow_sequence"),
        golden_position,
        event.get("sequence"),
    ):
        if isinstance(value, bool):
            continue
        if isinstance(value, int):
            return value
        if isinstance(value, str) and value.isnumeric():
            return int(value)
    return None


def _consumer_history(
    value: Any,
    context: str,
    *,
    default_codec: str,
    golden_values: bool,
) -> Sequence[Mapping[str, Any]]:
    """Normalize stored history into the values observed by replay execution."""

    history = _list(value, context, nonempty=True)
    normalized: list[Mapping[str, Any]] = []
    for index, raw_event in enumerate(history):
        event_context = f"{context}[{index}]"
        event = _object(raw_event, event_context)
        event_type = _string(event.get("event_type"), f"{event_context}.event_type")
        payload = _consumer_payload(
            _object(event.get("payload"), f"{event_context}.payload"),
            f"{event_context}.payload",
            default_codec=default_codec,
            golden_values=golden_values,
        )
        canonical_event: dict[str, Any] = {
            "event_type": event_type,
            "payload": payload,
        }
        sequence = _consumer_sequence(
            event,
            payload,
            golden_position=index + 1 if golden_values else None,
        )
        if sequence is not None:
            canonical_event["sequence"] = sequence

        # Golden-history event ids, timestamps, and namespaces are ignored when
        # its consumer creates history rows. Keep replay-only identity-bearing
        # fields when its consumer can observe them, but omit recorded_at: it is
        # runtime metadata rather than distinct regression behavior.
        if not golden_values:
            for field in ("id", "namespace"):
                if field in event:
                    canonical_event[field] = event[field]
        normalized.append(canonical_event)
    return normalized


def _semantic_codec_value(
    value: Mapping[str, Any],
    context: str,
    *,
    wire_backed: bool,
) -> Mapping[str, Any]:
    """Normalize tagged codec values independently of their fixture format."""

    kind = _string(value.get("type"), f"{context}.type")
    if kind == "null":
        return {"type": kind}
    if kind == "boolean":
        raw_boolean = value.get("value")
        if not isinstance(raw_boolean, bool):
            raise CorpusError(f"{context}.value must be a boolean")
        return {"type": kind, "value": raw_boolean}
    if kind == "long":
        raw_long = value.get("value")
        if isinstance(raw_long, bool) or not isinstance(raw_long, int | str):
            raise CorpusError(f"{context}.value must be an integer string")
        try:
            parsed_long = int(raw_long)
        except ValueError as error:
            raise CorpusError(f"{context}.value must be an integer string") from error
        if not -(2**63) <= parsed_long < 2**63:
            raise CorpusError(f"{context}.value must fit a signed 64-bit integer")
        return {"type": kind, "value": str(parsed_long)}
    if kind == "double":
        raw_double = value.get("value")
        if isinstance(raw_double, bool) or not isinstance(
            raw_double, int | float | str
        ):
            raise CorpusError(f"{context}.value must be a number or numeric string")
        try:
            parsed_double = float(raw_double)
        except ValueError as error:
            raise CorpusError(
                f"{context}.value must be a number or numeric string"
            ) from error
        if math.isnan(parsed_double):
            canonical_double = "nan"
        elif math.isinf(parsed_double):
            canonical_double = "-infinity" if parsed_double < 0 else "infinity"
        else:
            canonical_double = parsed_double.hex()
        return {"type": kind, "value": canonical_double}
    if kind == "bytes":
        aliases = [field for field in ("base64", "value_base64") if field in value]
        if not aliases:
            raise CorpusError(f"{context} must include base64 bytes")
        canonical_bytes: set[str] = set()
        for field in aliases:
            encoded = value[field]
            if not isinstance(encoded, str):
                raise CorpusError(f"{context}.{field} must be a string")
            normalized = _canonical_base64(encoded, f"{context}.{field}")
            if not isinstance(normalized, str):
                raise CorpusError(f"{context}.{field} must contain valid base64")
            canonical_bytes.add(normalized)
        if len(canonical_bytes) != 1:
            raise CorpusError(f"{context} contains conflicting base64 byte values")
        return {"type": kind, "base64": canonical_bytes.pop()}
    if kind == "string":
        raw_string = value.get("value")
        if not isinstance(raw_string, str):
            raise CorpusError(f"{context}.value must be a string")
        return {"type": kind, "value": raw_string}
    if kind == "array":
        if wire_backed:
            return {"type": kind}
        items = _list(value.get("items"), f"{context}.items")
        return {
            "type": kind,
            "items": [
                _semantic_codec_value(
                    _object(item, f"{context}.items[{index}]"),
                    f"{context}.items[{index}]",
                    wire_backed=False,
                )
                for index, item in enumerate(items)
            ],
        }
    if kind == "map":
        if wire_backed:
            return {"type": kind}
        entries = _list(value.get("entries"), f"{context}.entries")
        canonical_entries: dict[str, Mapping[str, Any]] = {}
        for index, raw_entry in enumerate(entries):
            entry_context = f"{context}.entries[{index}]"
            entry = _object(raw_entry, entry_context)
            key = entry.get("key")
            if not isinstance(key, str):
                raise CorpusError(f"{entry_context}.key must be a string")
            if key in canonical_entries:
                raise CorpusError(f"{context}.entries contains duplicate key {key!r}")
            canonical_entries[key] = _semantic_codec_value(
                _object(entry.get("value"), f"{entry_context}.value"),
                f"{entry_context}.value",
                wire_backed=False,
            )
        return {
            "type": kind,
            "entries": [
                {"key": key, "value": canonical_entries[key]}
                for key in sorted(canonical_entries)
            ],
        }
    raise CorpusError(f"{context}.type is unsupported")


def _codec_semantic(
    *,
    value: Mapping[str, Any] | None,
    wire_base64: str | Mapping[str, str] | Sequence[str | Mapping[str, str]] | None,
    operation: str,
    error: str | None,
) -> Mapping[str, Any]:
    """Return one format-neutral identity for payload-codec evidence."""

    return {
        "value": value,
        "wire": wire_base64,
        "failure_policy": {"operation": operation, "error": error},
    }


def _fixture_evidence(
    *,
    category: str,
    identity: str,
    path: str,
    protocol_version: str,
    semantic_value: Any,
    supersedes: tuple[str, ...] = (),
) -> Evidence:
    return Evidence(
        category=category,
        identity=identity,
        path=path,
        protocol_version=protocol_version,
        semantic_digest=_canonical_digest(semantic_value),
        supersedes=supersedes,
    )


def _codec_fixture(document: Mapping[str, Any], path: str, binding: str | None) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != CODEC_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={CODEC_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol = _object(document.get("protocol"), f"{path}.protocol")
    _string(protocol.get("codec"), f"{path}.protocol.codec")
    _string(protocol.get("schema"), f"{path}.protocol.schema")
    version = _string(protocol.get("version"), f"{path}.protocol.version")
    _nullable_string(protocol.get("fingerprint"), f"{path}.protocol.fingerprint")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")

    value = _object(document.get("value"), f"{path}.value")
    framing = _object(document.get("framing"), f"{path}.framing")
    _string(framing.get("encoding"), f"{path}.framing.encoding")
    wire = _nullable_string(framing.get("wire_base64"), f"{path}.framing.wire_base64")
    policy = _object(document.get("failure_policy"), f"{path}.failure_policy")
    operation = _string(policy.get("operation"), f"{path}.failure_policy.operation")
    if operation not in {"round_trip", "decode_reject", "encode_reject"}:
        raise CorpusError(f"{path}.failure_policy.operation is unsupported")
    error = _nullable_string(policy.get("error"), f"{path}.failure_policy.error")
    if operation in {"round_trip", "decode_reject"} and wire is None:
        raise CorpusError(f"{path} must include wire_base64 for {operation}")
    if operation == "round_trip" and error is not None:
        raise CorpusError(f"{path} round-trip evidence cannot declare an error")
    if operation != "round_trip" and error is None:
        raise CorpusError(f"{path} rejection evidence must declare its stable error policy")
    canonical_wire = (
        _canonical_base64(wire, f"{path}.framing.wire_base64")
        if wire is not None
        else None
    )

    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _codec_semantic(
        value=(
            _semantic_codec_value(
                value,
                f"{path}.value",
                wire_backed=operation == "round_trip",
            )
            if operation in {"round_trip", "encode_reject"}
            else None
        ),
        wire_base64=canonical_wire,
        operation=operation,
        error=error,
    )
    return [
        _fixture_evidence(
            category="codec",
            identity=identity,
            path=path,
            protocol_version=version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _replay_expected(
    value: Any,
    context: str,
    *,
    allow_resume: bool = False,
) -> Mapping[str, Any]:
    expected = _object(value, context)
    required = {"completed", "result", "commands"}
    allowed = required | ({"resume_with"} if allow_resume else set())
    if not required <= set(expected) or not set(expected) <= allowed:
        raise CorpusError(f"{context} must contain exactly {sorted(required)}")
    _boolean(expected["completed"], f"{context}.completed")
    commands = _list(expected["commands"], f"{context}.commands")
    for index, raw_command in enumerate(commands):
        command = _object(raw_command, f"{context}.commands[{index}]")
        if not command:
            raise CorpusError(f"{context}.commands[{index}] must not be empty")
        _string(command.get("type"), f"{context}.commands[{index}].type")
    return expected


def _replay_fixture(document: Mapping[str, Any], path: str, binding: str | None) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != REPLAY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={REPLAY_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol_version = _string(document.get("protocol_version"), f"{path}.protocol_version")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")
    workflow = _object(document.get("workflow"), f"{path}.workflow")
    required_workflow_fields = {"type", "arguments", "payload_codec"}
    if set(workflow) != required_workflow_fields:
        raise CorpusError(
            f"{path}.workflow must contain exactly {sorted(required_workflow_fields)}"
        )
    _string(workflow.get("type"), f"{path}.workflow.type")
    _list(workflow.get("arguments"), f"{path}.workflow.arguments")
    _string(workflow.get("payload_codec"), f"{path}.workflow.payload_codec")
    history = document.get("history")
    commands = document.get("command_sequence")
    if (history is None) == (commands is None):
        raise CorpusError(
            f"{path} must include exactly one of history or command_sequence"
        )
    canonical_history = (
        _consumer_history(
            history,
            f"{path}.history",
            default_codec=workflow["payload_codec"],
            golden_values=False,
        )
        if history is not None
        else []
    )
    if commands is not None:
        steps = _list(commands, f"{path}.command_sequence", nonempty=True)
        for index, raw_step in enumerate(steps):
            step = _object(raw_step, f"{path}.command_sequence[{index}]")
            allowed_step_fields = {"completed", "result", "commands", "resume_with"}
            if not set(step) <= allowed_step_fields:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] contains unsupported fields"
                )
            _replay_expected(
                step,
                f"{path}.command_sequence[{index}]",
                allow_resume=True,
            )
            if index < len(steps) - 1 and "resume_with" not in step:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] must provide resume_with for the next step"
                )
            if index == len(steps) - 1 and "resume_with" in step:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] has an unused resume_with value"
                )
    expected = _replay_expected(document.get("expected"), f"{path}.expected")
    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _replay_semantic(
        workflow_type=workflow["type"],
        workflow_input=workflow["arguments"],
        history=canonical_history,
        command_sequence=commands,
        expected=expected,
    )
    return [
        _fixture_evidence(
            category="replay",
            identity=identity,
            path=path,
            protocol_version=protocol_version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _avro_golden_fixture(document: Mapping[str, Any], path: str) -> list[Evidence]:
    _string(document.get("schema"), f"{path}.schema")
    _string(document.get("fingerprint"), f"{path}.fingerprint")
    version = "avro-value-v1"
    evidence: list[Evidence] = []
    sections = {
        "case": _list(document.get("cases"), f"{path}.cases", nonempty=True),
        "malformed": _list(document.get("malformed_frames"), f"{path}.malformed_frames", nonempty=True),
        "alternate": _list(document.get("alternate_map_orders"), f"{path}.alternate_map_orders", nonempty=True),
    }
    for section, entries in sections.items():
        for index, raw_entry in enumerate(entries):
            entry = _object(raw_entry, f"{path}.{section}[{index}]")
            name = _string(entry.get("name"), f"{path}.{section}[{index}].name")
            wire = entry.get("wire_base64")
            semantic_wire: str | Mapping[str, str] | Sequence[str | Mapping[str, str]]
            semantic_value: Mapping[str, Any] | None = None
            if section == "alternate":
                wire_values = _unique_strings(
                    wire,
                    f"{path}.{section}[{index}].wire_base64",
                )
                semantic_wire = [
                    _canonical_base64(
                        wire_value,
                        f"{path}.{section}[{index}].wire_base64[]",
                    )
                    for wire_value in wire_values
                ]
            elif section == "case":
                wire_value = _string(wire, f"{path}.{section}[{index}].wire_base64")
                semantic_wire = _canonical_base64(
                    wire_value,
                    f"{path}.{section}[{index}].wire_base64",
                )
                kind = _string(entry.get("kind"), f"{path}.{section}[{index}].kind")
                canonical_value: dict[str, Any] = {"type": kind}
                if "value" in entry:
                    canonical_value["value"] = entry["value"]
                if "value_base64" in entry:
                    canonical_value["value_base64"] = entry["value_base64"]
                semantic_value = _semantic_codec_value(
                    canonical_value,
                    f"{path}.{section}[{index}]",
                    wire_backed=True,
                )
            elif not isinstance(wire, str):
                raise CorpusError(f"{path}.{section}[{index}].wire_base64 must be a string")
            else:
                semantic_wire = _canonical_base64(
                    wire,
                    f"{path}.{section}[{index}].wire_base64",
                )

            operation = "decode_reject" if section == "malformed" else "round_trip"
            error = (
                _string(entry.get("error"), f"{path}.{section}[{index}].error")
                if section == "malformed"
                else None
            )
            semantic = _codec_semantic(
                value=semantic_value,
                wire_base64=semantic_wire,
                operation=operation,
                error=error,
            )
            evidence.append(
                _fixture_evidence(
                    category="codec",
                    identity=f"{version}:{section}:{name}",
                    path=path,
                    protocol_version=version,
                    semantic_value=semantic,
                )
            )
    return evidence


def _golden_history_fixture(
    document: Mapping[str, Any],
    path: str,
    *,
    require_single_case: bool,
) -> list[Evidence]:
    if document.get("fixture_schema") != GOLDEN_HISTORY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={GOLDEN_HISTORY_SCHEMA}")
    source = _object(document.get("source"), f"{path}.source")
    runtime = _string(source.get("runtime"), f"{path}.source.runtime")
    _string(source.get("package"), f"{path}.source.package")
    version = _string(source.get("version"), f"{path}.source.version")
    protocol_version = _string(
        source.get("worker_protocol_version"),
        f"{path}.source.worker_protocol_version",
    )
    cases = _list(document.get("cases"), f"{path}.cases", nonempty=True)
    if require_single_case and len(cases) != 1:
        raise CorpusError(
            f"new golden-history fixture {path} must contain exactly one minimal case"
        )
    evidence: list[Evidence] = []
    for index, raw_case in enumerate(cases):
        case = _object(raw_case, f"{path}.cases[{index}]")
        name = _string(case.get("name"), f"{path}.cases[{index}].name")
        family = _string(case.get("family"), f"{path}.cases[{index}].family")
        if family not in PHP_GOLDEN_HISTORY_FAMILIES:
            raise CorpusError(f"{path}.cases[{index}].family is unsupported")
        history = _consumer_history(
            case.get("history"),
            f"{path}.cases[{index}].history",
            default_codec="avro",
            golden_values=True,
        )
        expected_state = _object(
            case.get("expected_state"),
            f"{path}.cases[{index}].expected_state",
        )
        scenario = _string(case.get("scenario"), f"{path}.cases[{index}].scenario")
        semantic = _replay_semantic(
            workflow_type=PHP_GOLDEN_REPLAY_WORKFLOW,
            workflow_input=[scenario],
            history=history,
            command_sequence=None,
            expected={
                "completed": True,
                "result": expected_state,
                "commands": [{"type": "complete_workflow"}],
            },
        )
        evidence.append(
            _fixture_evidence(
                category="replay",
                identity=f"{runtime}@{version}:{name}",
                path=path,
                protocol_version=protocol_version,
                semantic_value=semantic,
            )
        )
    return evidence


def _run(command: Sequence[str], root: Path, *, check: bool = True) -> str:
    result = subprocess.run(
        command,
        cwd=root,
        check=False,
        capture_output=True,
        text=True,
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise CorpusError(f"{' '.join(command)} failed: {detail}")
    return result.stdout


def _policy(document: Mapping[str, Any], path: str) -> Mapping[str, Any]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("schema") != POLICY_SCHEMA:
        raise CorpusError(f"{path} must declare schema={POLICY_SCHEMA}")
    _string(document.get("repository"), f"{path}.repository")
    binding = document.get("binding")
    if binding is not None and binding not in SUPPORTED_BINDINGS:
        raise CorpusError(f"{path}.binding is unsupported")
    official_selectors = (
        OFFICIAL_BINDING_FIXTURE_SELECTORS.get(binding)
        if isinstance(binding, str)
        else None
    )
    categories = _object(document.get("categories"), f"{path}.categories")
    if not categories or not set(categories) <= SUPPORTED_CATEGORIES:
        raise CorpusError(f"{path}.categories must contain only replay and/or codec")
    for name, raw_category in categories.items():
        category = _object(raw_category, f"{path}.categories.{name}")
        fixtures = _list(category.get("fixtures"), f"{path}.categories.{name}.fixtures", nonempty=True)
        for index, raw_fixture in enumerate(fixtures):
            fixture = _object(raw_fixture, f"{path}.categories.{name}.fixtures[{index}]")
            fixture_glob = _string(
                fixture.get("glob"),
                f"{path}.categories.{name}.fixtures[{index}].glob",
            )
            fixture_format = _string(
                fixture.get("format"),
                f"{path}.categories.{name}.fixtures[{index}].format",
            )
            if fixture_format not in SUPPORTED_FORMATS:
                raise CorpusError(f"{path}.categories.{name}.fixtures[{index}].format is unsupported")
            if not fixture_format.startswith(name) and not (
                name == "codec" and fixture_format == "avro-value-golden-v1"
            ) and not (name == "replay" and fixture_format == "golden-history-v1"):
                raise CorpusError(f"{path}.categories.{name} contains a fixture for another category")
            if (
                official_selectors is not None
                and (name, fixture_glob, fixture_format) not in official_selectors
            ):
                raise CorpusError(
                    f"{path}.categories.{name}.fixtures[{index}] is not bound to "
                    f"this repository's official {binding} consumer"
                )
        guards = _list(category.get("guards"), f"{path}.categories.{name}.guards", nonempty=True)
        for index, raw_guard in enumerate(guards):
            guard = _object(raw_guard, f"{path}.categories.{name}.guards[{index}]")
            _string(guard.get("glob"), f"{path}.categories.{name}.guards[{index}].glob")
            patterns = guard.get("content_patterns")
            if patterns is not None:
                for pattern in _unique_strings(
                    patterns,
                    f"{path}.categories.{name}.guards[{index}].content_patterns",
                ):
                    try:
                        re.compile(pattern)
                    except re.error as error:
                        raise CorpusError(f"invalid guard regex {pattern!r}: {error}") from error
    return document


def _require_policy_extension(
    base_policy: Mapping[str, Any],
    current_policy: Mapping[str, Any],
    path: str,
) -> None:
    for field in ("repository", "binding"):
        if current_policy.get(field) != base_policy.get(field):
            raise CorpusError(f"{path}.{field} cannot change from the base policy")

    base_categories = _object(base_policy["categories"], "base categories")
    current_categories = _object(current_policy["categories"], "current categories")
    for category_name, raw_base_category in base_categories.items():
        if category_name not in current_categories:
            raise CorpusError(f"{path}.categories.{category_name} cannot be removed from the base policy")
        base_category = _object(raw_base_category, f"base categories.{category_name}")
        current_category = _object(
            current_categories[category_name],
            f"current categories.{category_name}",
        )
        for selector_type in ("fixtures", "guards"):
            base_selectors = _list(
                base_category[selector_type],
                f"base categories.{category_name}.{selector_type}",
            )
            current_selectors = _list(
                current_category[selector_type],
                f"current categories.{category_name}.{selector_type}",
            )
            for base_selector in base_selectors:
                if base_selector not in current_selectors:
                    raise CorpusError(
                        f"{path}.categories.{category_name}.{selector_type} cannot remove "
                        "or change a base selector"
                    )


def _tracked_worktree_files(root: Path) -> dict[str, bytes]:
    paths = _run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        root,
    ).split("\0")
    return {
        path: (root / path).read_bytes()
        for path in paths
        if path and (root / path).is_file()
    }


def _ref_files(root: Path, ref: str) -> dict[str, bytes]:
    paths = _run(["git", "ls-tree", "-r", "--name-only", "-z", ref], root).split("\0")
    return {
        path: _run(["git", "show", f"{ref}:{path}"], root).encode()
        for path in paths
        if path
    }


def _matches(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def _official_consumer(
    policy: Mapping[str, Any],
    category_name: str,
    path: str,
) -> tuple[str, tuple[str, ...]]:
    binding = policy.get("binding")
    if not isinstance(binding, str):
        raise CorpusError(
            f"{path} cannot prove a counterfactual without an official binding consumer"
        )
    category = _object(policy["categories"][category_name], f"categories.{category_name}")
    for raw_fixture in _list(category["fixtures"], f"categories.{category_name}.fixtures"):
        fixture = _object(raw_fixture, f"categories.{category_name}.fixtures[]")
        fixture_glob = _string(fixture["glob"], "fixture.glob")
        if not _matches(path, fixture_glob):
            continue
        fixture_format = _string(fixture["format"], "fixture.format")
        command = OFFICIAL_BINDING_CONSUMERS.get(
            (binding, category_name, fixture_glob, fixture_format)
        )
        if command is None:
            raise CorpusError(
                f"{path} has no registered official {binding} consumer command"
            )
        return f"{binding} {fixture_format}", command
    raise CorpusError(f"{path} is not selected by the {category_name} fixture policy")


def _materialize_consumer_tree(
    source_root: Path,
    target_root: Path,
    files: Mapping[str, bytes],
) -> None:
    for path, content in files.items():
        target = target_root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)

    for dependency_path in RUNTIME_DEPENDENCY_PATHS:
        source = source_root / dependency_path
        target = target_root / dependency_path
        if target.exists() or not source.is_dir():
            continue
        shutil.copytree(
            source,
            target,
            copy_function=os.link,
            symlinks=True,
        )


def _consumer_result(
    source_root: Path,
    files: Mapping[str, bytes],
    command: Sequence[str],
) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(
        prefix=".regression-corpus-consumer-",
        dir=source_root,
    ) as temporary:
        consumer_root = Path(temporary)
        _materialize_consumer_tree(source_root, consumer_root, files)
        return subprocess.run(
            command,
            cwd=consumer_root,
            check=False,
            capture_output=True,
            text=True,
        )


def _consumer_failure_detail(result: subprocess.CompletedProcess[str]) -> str:
    detail = result.stderr.strip() or result.stdout.strip() or "no diagnostic output"
    return detail[-2000:]


def _require_counterfactual_evidence(
    root: Path,
    policy: Mapping[str, Any],
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    current_evidence: Sequence[Evidence],
    added_fixture_paths: set[str],
    related_categories: set[str],
) -> dict[str, int]:
    verified = {category: 0 for category in related_categories}
    baseline_consumers: set[tuple[str, ...]] = set()

    for category_name in sorted(related_categories):
        evidence_paths = sorted(
            path
            for path in added_fixture_paths
            if any(
                item.path == path and item.category == category_name
                for item in current_evidence
            )
        )
        for path in evidence_paths:
            consumer_name, command = _official_consumer(policy, category_name, path)
            if command not in baseline_consumers:
                baseline = _consumer_result(root, base_files, command)
                if baseline.returncode != 0:
                    raise CorpusError(
                        f"official {consumer_name} consumer does not pass at the base "
                        f"revision without new evidence: {_consumer_failure_detail(baseline)}"
                    )
                baseline_consumers.add(command)

            isolated_head_files = dict(current_files)
            for other_path in added_fixture_paths - {path}:
                isolated_head_files.pop(other_path, None)
            head = _consumer_result(root, isolated_head_files, command)
            if head.returncode != 0:
                raise CorpusError(
                    f"new {category_name} evidence {path} does not pass the official "
                    f"{consumer_name} consumer at the current revision: "
                    f"{_consumer_failure_detail(head)}"
                )

            base_with_evidence = dict(base_files)
            base_with_evidence[path] = current_files[path]
            counterfactual = _consumer_result(root, base_with_evidence, command)
            if counterfactual.returncode == 0:
                raise CorpusError(
                    f"new {category_name} evidence {path} passes the official "
                    f"{consumer_name} consumer at both base and current revisions; "
                    "it does not prove the guarded regression"
                )
            verified[category_name] += 1

    return verified


def _inventory(
    policy: Mapping[str, Any],
    files: Mapping[str, bytes],
    *,
    new_paths: set[str] | None = None,
) -> list[Evidence]:
    binding = policy.get("binding")
    evidence: list[Evidence] = []
    selected_paths: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        category = _object(raw_category, f"categories.{category_name}")
        for raw_fixture in _list(category["fixtures"], f"categories.{category_name}.fixtures"):
            fixture = _object(raw_fixture, f"categories.{category_name}.fixtures[]")
            pattern = _string(fixture["glob"], "fixture.glob")
            fixture_format = _string(fixture["format"], "fixture.format")
            for path in sorted(candidate for candidate in files if _matches(candidate, pattern)):
                if path in selected_paths:
                    raise CorpusError(f"fixture path {path} is selected more than once")
                selected_paths.add(path)
                document = _json(files[path], path)
                if fixture_format == "codec-regression-v1":
                    parsed = _codec_fixture(document, path, binding if isinstance(binding, str) else None)
                elif fixture_format == "replay-regression-v1":
                    parsed = _replay_fixture(document, path, binding if isinstance(binding, str) else None)
                elif fixture_format == "avro-value-golden-v1":
                    parsed = _avro_golden_fixture(document, path)
                else:
                    parsed = _golden_history_fixture(
                        document,
                        path,
                        require_single_case=new_paths is not None and path in new_paths,
                    )
                if any(item.category != category_name for item in parsed):
                    raise CorpusError(f"{path} produced evidence for the wrong category")
                evidence.extend(parsed)

    identities = Counter(item.identity for item in evidence)
    repeated_identities = sorted(identity for identity, count in identities.items() if count > 1)
    if repeated_identities:
        raise CorpusError(f"duplicate fixture identities: {repeated_identities}")
    semantics = Counter((item.category, item.semantic_digest) for item in evidence)
    duplicate_semantics = sorted(key for key, count in semantics.items() if count > 1)
    if duplicate_semantics:
        paths = {
            key: sorted(item.path for item in evidence if (item.category, item.semantic_digest) == key)
            for key in duplicate_semantics
        }
        raise CorpusError(f"duplicate semantic fixtures: {paths}")
    return evidence


def _fixture_paths(policy: Mapping[str, Any], files: Mapping[str, bytes]) -> set[str]:
    return {
        path
        for raw_category in _object(policy["categories"], "categories").values()
        for raw_fixture in _list(
            _object(raw_category, "category")["fixtures"],
            "category.fixtures",
        )
        for path in files
        if _matches(path, _string(_object(raw_fixture, "fixture")["glob"], "fixture.glob"))
    }


def _changed_paths(root: Path, base_ref: str) -> tuple[set[str], set[str]]:
    output = _run(["git", "diff", "--name-status", "--find-renames", base_ref, "--"], root)
    changed: set[str] = set()
    added: set[str] = set()
    for line in output.splitlines():
        parts = line.split("\t")
        status = parts[0]
        paths = parts[1:]
        if not paths:
            continue
        changed.update(paths)
        if status.startswith("A"):
            added.add(paths[-1])
    untracked = {
        path
        for path in _run(
            ["git", "ls-files", "--others", "--exclude-standard"],
            root,
        ).splitlines()
        if path
    }
    return changed | untracked, added | untracked


def _guard_matches(
    root: Path,
    base_ref: str,
    changed: set[str],
    raw_guard: Any,
) -> bool:
    guard = _object(raw_guard, "guard")
    matching = sorted(path for path in changed if _matches(path, _string(guard["glob"], "guard.glob")))
    if not matching:
        return False
    patterns = guard.get("content_patterns")
    if patterns is None:
        return True
    diff = _run(["git", "diff", "--unified=0", base_ref, "--", *matching], root)
    untracked = set(
        _run(["git", "ls-files", "--others", "--exclude-standard"], root).splitlines()
    )
    for path in matching:
        if path in untracked and (root / path).is_file():
            diff += "\n" + (root / path).read_text(encoding="utf-8", errors="replace")
    changed_content = "\n".join(
        line[1:]
        for line in diff.splitlines()
        if line.startswith(("+", "-")) and not line.startswith(("+++", "---"))
    )
    return any(re.search(pattern, changed_content) for pattern in patterns)


def validate(
    root: Path,
    policy_path: Path,
    base_ref: str | None,
    *,
    enforce_counterfactual: bool = True,
) -> dict[str, Any]:
    policy_file = (policy_path if policy_path.is_absolute() else root / policy_path).resolve()
    try:
        policy_relative_path = policy_file.relative_to(root).as_posix()
    except ValueError as error:
        raise CorpusError("policy must be inside the repository root") from error
    policy = _policy(_json(policy_file.read_bytes(), str(policy_path)), str(policy_path))
    current_files = _tracked_worktree_files(root)
    changed: set[str] = set()
    added_paths: set[str] = set()
    base_files: dict[str, bytes] = {}
    base_evidence: list[Evidence] = []
    if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
        _run(["git", "rev-parse", "--verify", f"{base_ref}^{{commit}}"], root)
        changed, added_paths = _changed_paths(root, base_ref)
        base_files = _ref_files(root, base_ref)
        raw_base_policy = base_files.get(policy_relative_path)
        base_policy = (
            _policy(_json(raw_base_policy, policy_relative_path), policy_relative_path)
            if raw_base_policy is not None
            else policy
        )
        if raw_base_policy is not None:
            _require_policy_extension(base_policy, policy, str(policy_path))
        for path in _fixture_paths(base_policy, base_files):
            current_content = current_files.get(path)
            if current_content != base_files[path] and current_content is not None:
                if _canonical_wire_migration(base_files[path], current_content):
                    base_files[path] = current_content
                    continue
            if current_content != base_files[path]:
                raise CorpusError(f"immutable fixture file {path} was changed, moved, or removed")
        base_evidence = _inventory(base_policy, base_files)
    current_evidence = _inventory(policy, current_files, new_paths=added_paths)

    current_by_id = {item.identity: item for item in current_evidence}
    base_by_id = {item.identity: item for item in base_evidence}
    for identity, previous in base_by_id.items():
        current = current_by_id.get(identity)
        if current is None:
            raise CorpusError(f"immutable fixture {identity} was removed")
        if current.path != previous.path or current.semantic_digest != previous.semantic_digest:
            raise CorpusError(f"immutable fixture {identity} was changed; append a superseding fixture instead")
    for item in current_evidence:
        for superseded in item.supersedes:
            previous = current_by_id.get(superseded)
            if previous is None:
                raise CorpusError(f"{item.identity} supersedes unknown fixture {superseded}")
            if previous.category != item.category or previous.protocol_version == item.protocol_version:
                raise CorpusError(
                    f"{item.identity} must supersede evidence in the same category at an older protocol version"
                )

    counts: dict[str, dict[str, int | bool]] = {}
    related_categories: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        current_count = sum(item.category == category_name for item in current_evidence)
        base_count = sum(item.category == category_name for item in base_evidence)
        new_fixture_evidence = sum(
            item.category == category_name and item.path in added_paths
            for item in current_evidence
        )
        related = False
        if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
            category = _object(raw_category, f"categories.{category_name}")
            related = any(
                _guard_matches(root, base_ref, changed, guard)
                for guard in _list(category["guards"], f"categories.{category_name}.guards")
            )
            if related:
                related_categories.add(category_name)
            if related and current_count <= base_count:
                raise CorpusError(
                    f"{category_name} implementation changed but its corpus did not grow "
                    f"(base={base_count}, current={current_count})"
                )
            if related and new_fixture_evidence == 0:
                raise CorpusError(
                    f"{category_name} implementation changed but corpus growth has no "
                    "newly added fixture evidence"
                )
        counts[category_name] = {
            "base": base_count,
            "current": current_count,
            "new_fixture_evidence": new_fixture_evidence,
            "related_change": related,
        }

    counterfactual_counts = {category: 0 for category in counts}
    if related_categories and enforce_counterfactual:
        added_fixture_paths = added_paths & _fixture_paths(policy, current_files)
        counterfactual_counts.update(
            _require_counterfactual_evidence(
                root,
                policy,
                base_files,
                current_files,
                current_evidence,
                added_fixture_paths,
                related_categories,
            )
        )
    for category_name, count in counterfactual_counts.items():
        counts[category_name]["counterfactual_fixture_paths"] = count

    return {
        "schema": POLICY_SCHEMA,
        "repository": policy["repository"],
        "base_ref": base_ref,
        "changed_paths": len(changed),
        "counterfactual_enforced": enforce_counterfactual,
        "counts": counts,
        "status": "pass",
    }


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--policy", type=Path, default=Path("regression-corpus-policy.json"))
    parser.add_argument("--base-ref")
    parser.add_argument(
        "--skip-counterfactual",
        action="store_true",
        help="run structural inventory checks without invoking binding consumers",
    )
    args = parser.parse_args(argv)
    try:
        result = validate(
            args.root.resolve(),
            args.policy,
            args.base_ref,
            enforce_counterfactual=not args.skip_counterfactual,
        )
    except (CorpusError, OSError) as error:
        print(f"regression corpus validation failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
