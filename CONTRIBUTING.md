# Contributing

Run ECS in check mode, PHPStan, and focused PHPUnit coverage for changed code.

Replay and payload-codec fixes also follow the organization
[regression-corpus contract](https://github.com/durable-workflow/.github/tree/main/regression-corpus).
Use one-case golden histories under `tests/Fixtures/V2/GoldenHistory/` whenever
the official replay runner can consume them. Otherwise use
`tests/Fixtures/V2/ReplayRegression/`. Shared codec evidence belongs under
`tests/Fixtures/V2/CodecRegression/` and in every applicable official binding.

Fixtures preserve protocol version, value and type, framing, and stable failure
policy. Existing evidence is append-only. Run:

```bash
python scripts/ci/validate-regression-corpus.py --base-ref <target>
```
