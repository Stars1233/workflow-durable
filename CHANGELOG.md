# Changelog

## Unreleased

- Platform conformance suite version 39 publishes the revision-bound CLI
  output-schema manifest and its complete per-command JSON envelope schema
  closure with HTTPS identities and SHA-256 byte bindings.
- Advanced the Workflow package source to `2.0.0-rc.11` for the suite version
  39 authority. The aggregate recommended product tuple remains RC5 until
  independently published release-candidate artifacts pass exact-current
  qualification.
- Historical command-contract gaps remain visible in operator metrics without
  warning fleet correctness once their runs are closed. Open runs still warn
  when operator command forms lack required safety data.
- Projection repair can be limited to one namespace, and resolved wait
  snapshots now compare absent optional values consistently with projected
  nulls so repeated repair is idempotent.
- Replaced the prerelease JSON-in-Avro wrapper with the fixed recursive
  `durable_workflow.protocol.Value` schema and standard Avro single-object
  framing. Native adapters now preserve booleans, signed 64-bit integers,
  finite doubles, bytes, UTF-8 text, lists, and string-keyed maps.
- Added a backup-first prerelease-history migration that recursively rewrites
  inline and external wrapper copies in retained event snapshots. Replacement
  external objects receive verified hashes and sizes while original objects
  remain recoverable, and affected exported histories must replay before the
  migration reports success.
- Removed the package-owned hosted control-plane and runtime-target contract.
  Embedded Laravel, independent self-hosted Server, and managed Cloud remain
  separate deployment choices; Cloud placement stays behind the namespace
  endpoint.
- Platform conformance suite version 37 Rust signal/query scenarios install
  the exact synchronized `durable-workflow =2.0.0-rc.5` crates.io artifact,
  with prior observed bindings preserved by source revision and digest.
- Embedded and standalone activity heartbeats now use the same
  attempt-before-execution row-lock order as timeout enforcement. Accepted
  heartbeats renew the current attempt deadline, while stale scanner snapshots
  cannot time out live, replaced, or already-closed attempts.
- Release recovery now accepts release-candidate plans only when they retain a
  coherent immutable beta qualification. The versioned recovery-consumer
  conformance contract and release-docs audit now cover the `rc` channel.

- Release-plan recovery now consumes immutable, exact-version release-note
  preparation authority before publishing a newly recorded plan.
- Explicit release recovery rejects terminally superseded plans before and
  after publication preflight while keeping completed-plan verification
  idempotent.
- Standalone workers now receive accepted declared signals even when the host
  has no embedded workflow definition or local wait projection. Signal tasks
  retain command order ahead of queued updates, and QueueFake update completion
  uses the configured workflow-run model query. Accepted signal inputs are also
  persisted on their history event so public-history consumers observe the same
  values as workers and query replay.
- Workflow-task claims and renewals now resolve
  `workflows.v2.workflow_task_lease_seconds` at runtime across remote,
  queued, timer, local-activity, and repair-driven execution paths. Embedded
  Laravel hosts retain an explicit 300-second default and may set
  `DW_V2_WORKFLOW_TASK_LEASE_SECONDS` before caching configuration.

## 2.0.0-alpha.179

Workflow 2.0.0-alpha.179 keeps the Durable Workflow 2.0 PHP package
conformance claim aligned to platform conformance suite version 12. For this
alpha, upgrade-path migration runtime coverage remains outside the release
claim; claiming that category requires a versioned migration scenario manifest
and published-artifact conformance evidence.

- `php artisan workflow:v2:replay-conformance` now reports `outcome: pass`
  when every Workflow PHP replay shard scenario passes, so host replay
  conformance can compose the PHP shard with Python and server evidence
  without treating the shard itself as non-passing.
