# ADR-0149: `outbreak serve` keeps what the local loop reads, and a shard reads its own map

**Status:** Accepted
**Date:** 2026-11-11
**Amends:** [0133](0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md): decision 1, what the daemon keeps; decision 2, where its socket is; decision 4, a daemon in CI; decision 5, the interfaces the gate asks it through
**Amends:** [0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): decision 4, which services `auto` claims on

## Context

ADR-0133 has `outbreak serve` keep the coverage map as an index from each
site and line to its tests, the test inventory, the killer history, each
file's digest and the spans of its functions, and has a pull request's shard
hold one daemon so that its chunks read their maps once.

What the gate reads does not take those slices:

- every consumer of a covering set gets it from a map the runner already
  decoded in memory, or from its own run's fresh recording;
- the one read of a whole map is the value-reader scan's (ADR-0037), and
  that scan needs the whole decoded map, so a daemon answering slices saves
  it nothing;
- the killer history is in the ledgers `watch` already holds, and each
  `watch` process remembers its listings across rounds.

Each shard's report names the read of the whole map (`value reader
coverage`) and the scan (`value readers`) apart (ADR-0016). On run
43057471237 a shard of 80 mutants took 3 s for the read and 16 s for the
scan, beside 7 s for `coverage`: the part a daemon in CI would save is the
smallest of the three, and the scan is the cost.

A Unix socket's address holds 103 bytes of path on macOS and the BSDs and
107 on Linux. `.mutation-gate/outbreak/state.sock` under a project at a long
path is longer, and the daemon then never listens.

ADR-0134 has `shards.pull: auto` claim on github.com, the first service on
the list of those the claim race passes on. The race has not run. Run
43062122788 holds eight claim artifacts of one item and two of another, each
created minutes after the one before: the artifact service accepted every
claim, so a written claim kept no later shard off the item.

## Decision

1. **`outbreak serve` keeps the proofs `watch` rounds keep, by key, and the
   static graph's names (ADR-0132).** It keeps no index of the coverage map,
   no test inventory, no killer history and no digests or spans of
   functions; the PHP gate reads those from the files they are in, as it
   does without the daemon.
2. **CI runs no daemon.** Each chunk of a shard reads the map its files
   need, and the value-reader scan stays the PHP runner's, held to one
   codebase scan per shard (ADR-0121). What the scan costs is profiled and
   cut in the runner.
3. **The gate asks the daemon through internal interfaces of the core.**
   `KeepsProofs` holds the proofs by key; `ReachGraph`, which Outbreak's
   binary and its daemon alone answer, is an internal interface of the core
   as `ConvertsPhpReports` is (ADR-0119), not a port; and the proof cache
   (ADR-0131) is asked through `ProofCache`, internal in the same way. The
   CLI hands the Outbreak adapter the daemon's client, `OutbreakDaemon` of
   the Serve adapter, so neither adapter names the other.
4. **The daemon's socket is beside its files where that path fits a
   socket's address, and in the user's own directory otherwise:**
   `/tmp/mutation-gate-<uid>/<the first 16 hex digits of the root's
   SHA-256>.sock`, the directory made `0700` and checked as the daemon's own
   directory is (ADR-0133 decision 2). The gate's PHP chooses the same path
   by the same rule.
5. **`shards.pull: auto` steals on every service.** The list of services
   whose claims pass the race is empty until a service passes it; `claim`
   stays a value a project may set.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Build the map index and a daemon per shard** | It answers slices nobody reads, and saves the shard's smallest part. |
| **Keep `auto` claiming on github.com** | A second claim of an item is accepted, so a shard that ran out of its own items runs an item another shard is running. |

## Consequences

- A pull request's shard reads its map once per chunk, as without the
  daemon; the scan is the step a profile works on.
- `outbreak serve` keeps less state: the proofs and the graph's names.
- A run on github.com steals: two shards may run one item, and the verdict
  keeps one result for it (ADR-0134 decision 6).

## Related

- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the report's steps
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): the value-reader scan
- [ADR-0119](0119-outbreak-s-core-converts-a-run-s-php-report-into-the-gate-s-map.md): an internal interface beside `MapEngine`
- [ADR-0121](0121-a-shard-lost-to-its-machine-says-so.md): one codebase scan per shard
- [ADR-0131](0131-proofs-are-shared-across-scopes-by-their-keys.md): the proof cache
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): the static graph
- [ADR-0133](0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md): `outbreak serve`
- [ADR-0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): shards pull the work left
