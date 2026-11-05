# ADR-0088: Pest's warm workers compile through opcache, keeping every mutated file out

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): its rule 15, a Pest worker running with opcache off; [0075](0075-the-verification-tail-may-fork-from-a-booted-pest-worker.md): "Opcache stays off"

## Context

Under `pest.patch` and `runner.workers: fork`, each mutant's own run, and
each run of the verification tail, forks from a warm worker (ADR-0023,
ADR-0070). The worker loads nothing of the project, and runs with opcache
off, so no child is served what another compiled from its mutant. Each
child therefore compiles Pest, PHPUnit, Symfony's console and the project's
code from source before its first test.

On run 42036642441, shard 3 of the gate's own CI judged 3,113 mutants in
705 s on four cores. No killed mutant's run took less than about 0.24 s,
p10 0.24 s and median 0.31 s, against 0.03 s for a fork alone. Forked
from a warm parent on a laptop, a child that runs Pest with a filter
matching nothing takes 0.41 to 0.44 s with opcache off, whether it loads
one test file or three. With opcache on in the parent, and so shared by
every child, it takes 0.23 to 0.30 s. The tail's baselines, replays,
trials and controls are the same kind of child, which is why forking the
tail (ADR-0070) saved almost nothing there: on #551's own run, shard 3's
baselines took 122 s against 114 s before it.

## Decision

1. **`pest.workerOpcache`, true by default, turns opcache on in Pest's warm
   workers.** Under `pest.patch`, each worker of a run, the mutants' and
   the tail's, starts PHP with `opcache.enable_cli=1` and
   `opcache.blacklist_filename` naming a blacklist in the run's warm
   directory. Every child a worker forks shares its opcache, so a file one
   child compiled is compiled once for all of them. `false` keeps the
   workers as ADR-0023 rule 15 has them, with opcache off. It is in key
   item 3 (ADR-0007), as `runner.workers` is.
2. **The blacklist keeps out every file a mutant can be served in place of,
   and every copy served.** It names each file the run mutates, resolved
   as opcache keys it, pest-plugin-mutate's folder of mutated copies, and
   the gate's own directory, which holds the unmutated copies the tail
   serves. Opcache neither caches nor serves any file a blacklisted path
   begins, so a child compiles its mutant, and any original a mutant is
   served in place of, from source, as it does with opcache off.
3. **The guard that distrusts a run opcache could have served reads the
   blacklist.** A trial's run is unjudged where opcache could have served
   a cached original of the mutated file (ADR-0004). Opcache's being on no
   longer counts where its blacklist keeps that original out.
4. **A worker started with no blacklist runs with opcache off,** as every
   worker did before.

### Why a child of a caching worker runs what a child of an uncached one runs

- **No mutant is cached.** A mutant reaches PHP only through the files the
  run mutates, whose originals and copies the blacklist names. Opcache
  compiles them from source on every include, as it does with opcache off.
- **Everything cached is unmutated.** Every other file is the same file in
  every child of the run, so its compiled form is the same whichever child
  compiled it first.
- **The same freshness.** Opcache caches compiled code, not state: each
  child still runs every file's top-level code, its bootstraps and its test
  files as a fresh process does, as ADR-0023 has it.

`PatchedTest`, in the runner contract, runs the same mutants from warm
workers with opcache on and with it off, with a real patched Pest, and
fails where their records differ.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep opcache off** | Every child compiles Pest and PHPUnit afresh, the larger part of a short run's time. |
| **Opcache's file cache, kept between runs** | It outlives the run, so a stale entry could serve a later run; the shared memory dies with the run's workers. |
| **Blacklist only the mutated copies** | Opcache keys by path, and Pest's override serves a mutant in place of its original's path, so the original's path must be kept out too. |
| **Off by default** | It changes no result, and every run of a patched Pest pays for it otherwise. |

## Consequences

- Each child of a run compiles only its test files, the files the run
  mutates, and what no earlier child loaded.
- `doctor`'s opcache finding is unchanged: a worker turns opcache on for
  its own children only.

## References

- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): rule 15, the warm workers
- [ADR-0070](0070-the-verification-tail-forks-from-warm-workers.md): the tail forks from warm workers
- [ADR-0075](0075-the-verification-tail-may-fork-from-a-booted-pest-worker.md): the booted tail worker
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the guard on a trial's run
