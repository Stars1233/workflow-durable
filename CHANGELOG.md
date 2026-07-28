# Changelog

## Unreleased

- Advanced Workflow to the synchronized Durable Workflow `2.0.0-rc.1`
  product train. This is the only supported 2.0 prerelease baseline; earlier
  alphas and beta tuples remain historical and receive no compatibility shim.
- Removed the package-owned hosted control-plane and runtime-target contract.
  Embedded Laravel, independent self-hosted Server, and managed Cloud remain
  separate deployment choices; Cloud placement stays behind the namespace
  endpoint.
- Platform conformance Rust signal/query scenarios continue to install the
  exact compatible `durable-workflow =2.0.0-rc.1` crates.io artifact.
- Activity heartbeat handling now renews the persisted heartbeat deadline on
  every accepted heartbeat. Timeout enforcement locks and validates the
  current running attempt before closing it, so a stale scanner snapshot
  cannot time out a live or already-closed attempt.
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
