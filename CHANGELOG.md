# Changelog

## Unreleased

- Workflow `2.0.0-rc.39` keeps database-backed Watchdog chains on fresh
  delayed jobs, preserving queue affinity without accumulating attempts. A
  generation lease converges duplicate ticks and safely releases ownership
  when successor dispatch fails.
- Workflow `2.0.0-rc.38` publishes worker protocol 1.15 as the current
  conformance authority. The package contract and retained OpenAPI and AsyncAPI
  specs now define the version-gated message-stream completion fields together.
- Reserved runtime delivery now keeps durable message-stream input separate
  from user-authored one-shot signals. The Workflow package source advances to
  `2.0.0-rc.37` with worker protocol 1.15 for portable stream consumption.
- Standalone child-workflow completion now applies the recorded parallel-group
  path before resuming the parent. Successful child-only and mixed groups wait
  for every member, serialize concurrent child closures on the parent run, and
  create a single replay task while preserving retry and fail-fast behavior.
- Advanced the Workflow package source to `2.0.0-rc.36` for the service-mode
  child-completion barrier.
- Standalone workflow-task completion now validates and records deterministic
  parallel activity, child-workflow, and timer metadata. Nested groups must be
  complete and sequence-aligned before transport, and timer waits now expose the
  same group/path diagnostics as activity and child waits.
- Advanced the Workflow package source to `2.0.0-rc.34` for the service-mode
  parallel-group contract.
- Advanced the Workflow package source to `2.0.0-rc.33`. Laravel now
  container-constructs embedded v2 workflow and activity classes before the
  engine binds durable runtime context. A package-owned transition contract,
  isolated v2 queue default, upgrade-status command, supported-intersection
  matrix, and published-artifact smoke make the stable-v1 to embedded-v2 path
  executable without transferring v1 history.
- Advanced the Workflow package source to `2.0.0-rc.32`. History import now
  validates Avro codec declarations only at schema-owned payload rows and
  envelopes, preserving codec-looking memo and search-attribute data while
  real non-Avro payload declarations continue to fail closed.
- Platform conformance suite version 41 makes the lifecycle-neutral worker
  protocol OpenAPI bytes the current conformance authority. The former
  beta-worded binding remains available only through its explicit historical
  identity, while protocol version 1.13 and every wire shape remain unchanged.
- Advanced the Workflow package source to `2.0.0-rc.14` for the corrected
  conformance authority. Durable Workflow 2.0 remains a release candidate.
- Platform conformance suite version 40 publishes the revision-bound CLI
  output-schema manifest and its complete JSON envelope plus JSONL record
  schema closure with HTTPS identities and SHA-256 byte bindings. The
  suite-39 revision remains retained with its original bytes.
- Advanced the Workflow package source to `2.0.0-rc.13` for platform protocol
  catalog 16. Its conformance bindings resolve the catalog and protocol-spec
  bytes through immutable public documentation provenance. The aggregate
  recommended product tuple remains RC5 until independently published
  release-candidate artifacts pass exact-current qualification.
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
