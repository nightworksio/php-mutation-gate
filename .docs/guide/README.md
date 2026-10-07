# Guide

Start with the [quick start](../../README.md#quick-start), then read what fits
what you are doing.

**Setting up CI:** [running the gate in CI](ci/README.md), then the page for
your CI, then [proofs and trust](concepts/proofs-and-trust.md) before you give
any job a credential.

**Speeding up a slow suite:** [holding tests](concepts/holding-tests.md) for
code every test runs through, and the `shards`, `budget` and `pest.patch`
keys in the [configuration](../reference/configuration.md#every-key).

**Working locally:** the [pre-push hook and watch mode](recipes/pre-push-and-watch.md),
and [survivors in your editor](recipes/editors.md).

**When the gate refuses, fails or warns:** every message links to its section
of [troubleshooting](troubleshooting.md).

**Extending the gate:** [writing an adapter](extending/writing-an-adapter.md).

The reference lists every [command](../reference/cli.md),
[configuration key](../reference/configuration.md),
[report](../reference/reports.md),
[environment variable](../reference/environment.md) and
[file](../reference/files.md).
