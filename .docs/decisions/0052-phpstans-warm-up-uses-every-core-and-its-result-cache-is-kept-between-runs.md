# ADR-0052: PHPStan's warm-up uses every core, and its result cache is kept between runs

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): its PHPStan warm-up, for the processes it runs on and where its result cache lives

## Context

ADR-0020 has the PHPStan adapter warm up once in every shard: one analysis of
every file the config names, which saves PHPStan's result cache, so that each
check reanalyses only the mutant and the files whose view of it changed. The
warm-up and the checks read one config,
`.mutation-gate/phpstan/check.neon`, which sets
`maximumNumberOfProcesses: 1`, because the checks run side by side, one
process each. So the warm-up ran in one process too. ADR-0020 also rejected
discarding the analysers' caches between runs, since a cold warm-up costs
minutes in every shard. Yet the result cache lived wherever the project's
`tmpDir` put it, which no CI job kept, so every shard warmed up cold.

On the gate's own full run on main (run 41443050939), the warm-up took about
2.5 minutes of every shard's 18 to 20, on one of the runner's 4 cores.
On an 8-core laptop, a warm-up of the gate's own code on every core took 26 to 92 seconds
where one process took 153, and a check after it took 2 to 3 seconds. PHPStan
reads the result cache a warm-up on every core saved, as long as both configs
include the same project config and name the same `resultCachePath`.

## Decision

1. **The warm-up reads a config of its own,
   `.mutation-gate/phpstan/warm.neon`.** It includes the project's config, as
   `check.neon` does, and sets `reportUnmatchedIgnoredErrors: false`, but no
   `maximumNumberOfProcesses`, so it runs on as many processes as the
   project's config allows. The checks keep `check.neon` and one process each.
2. **The result cache is the gate's own:
   `.mutation-gate/phpstan/resultCache.php`.** Both configs set it as
   `resultCachePath`. PHPStan's other files stay in the project's `tmpDir`.
   A run outside CI starts from the cache the last local run left.
3. **The action keeps the result cache in the Actions cache.** Before the
   gate runs, in a shard and in the one-job action alike, the action restores
   the latest cache under
   `mutation-gate-phpstan-<os>-<arch>-<PHP version>-`. After the run, it saves
   the cache the run left, under that prefix and the file's digest, where it
   differs from the one restored. GitHub keeps a cache for the ref that saved
   it, so a pull request's cache is read by that pull request alone, and the
   default branch's by every ref.
4. **PHPStan stays the judge of what its cache holds.** It checks a cache
   against the code, its version, PHP's and its config, and analyses afresh
   whatever the cache no longer holds. So a cache from an earlier run changes
   how long the warm-up takes, never what it finds, and the key holds nothing
   PHPStan does not check itself.

This amends ADR-0020's PHPStan warm-up: it runs on every core its config
allows, and its result cache is the gate's, kept between runs.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Warm up once in the plan job and hand the cache to the shards** | The plan's warm-up would sit on the run's critical path before any shard starts, and a cache kept between runs leaves the warm-up only what changed. |
| **Keep the project's `tmpDir` in the Actions cache** | The gate cannot name that directory before PHPStan resolves the project's config, and it holds PHPStan's container cache beside the result cache. |
| **Leave the warm-up in one process** | It costs every shard minutes of a runner whose other cores wait. |
| **Key the cache by the lock file and the config** | PHPStan checks both itself, and a key that changes with them would leave every run that moves a package with no cache at all. |

## Consequences

- A shard's warm-up runs on every core, and on a ref whose cache is warm it
  analyses only what changed since that cache.
- A project's own PHPStan runs and the gate's warm-up no longer share a
  result cache.
- Another CI keeps the cache by keeping `.mutation-gate/phpstan/resultCache.php`
  between jobs, as its ledger is kept.

## Related

- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the static analyser's checks, which this amends
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a cache of one ref may be read by
