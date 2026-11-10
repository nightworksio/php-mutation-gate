# ADR-0133: `outbreak serve` holds the map, the ledgers and the coverage in memory

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0078](0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md): which daemon `watch` and the hooks talk to, and its consequence that CI runs no daemon

## Context

`mutation-gate serve` keeps the gate loaded for one project and forks a child
for each request (ADR-0078). A child holds what it decodes only for its own
request: what a round reads, a later round reads again.

Measured on the quick-start fixture (one source file, 12 mutants) on an
8-core laptop, 2026-11-09:
- a round's plan takes 3.8 to 4.3 CPU-s, starting Pest three times, a PHP
  probe and about eight `git` calls;
- each round rewrites the ledger, the maps and `plan.json`, 4.8 to 7.8 MB on
  the gate's own tree, and the test inventory `tests.xml` alone is 4.5 to
  10.8 MB.

In CI a shard's runner invocations each read their map anew. A pull
request's shard runs in chunks of `plan.chunkMinutes` (ADR-0032), and each
chunk readies the map it reads and scans the codebase for the tests that read
each value (`src/Adapter/Pest/MutationRun.php:207-208`; the memo is keyed by
the chunk's own files, `src/Adapter/Pest/WholeMap.php:60-70`). Run
42494457581 made 106 such coverage laps over 19 shards, 28.7 s on average.
ADR-0118 makes reading a whole map take seconds; each chunk still reads it.

`outbreak serve` owns the local loop: it is the daemon, the gate's planner
and Pest's workers are its children, and the PHP `serve` is its identical
fallback.

## Decision

1. **`outbreak serve` keeps a project's state in memory**: the coverage map
   as an index from each site and line to its tests, the proofs of the
   ledgers it read by key, the test inventory, the killer history, each
   file's digest and the spans of its functions, and the static graph
   (ADR-0132). The PHP gate asks it for slices of these instead of reading
   the files they come from.
2. **Locally it is the daemon `watch` starts.** `watch` starts `outbreak
   serve` in a process group of its own, at the priority the local settings
   give it (ADR-0120), and it starts the PHP `serve` as its child, which
   `watch` and the hooks talk to at `.mutation-gate/serve.sock` under every
   rule of ADR-0078: one root, the client's identity checked on each
   request, the directory `0700` and the socket its owner's, the peer
   checked both ways and root refused, stopping after `local.serveIdle` and
   on an identity change, and started only by `watch` as `local.serveStart`
   says. It lives as long as that child, and the PHP `serve` is started
   alone where it does not listen in time. The gate's PHP asks its state at
   `.mutation-gate/outbreak/state.sock`, in a directory kept to its owner,
   checking the socket and the peer as a client of `serve` does. One daemon
   serves a root, held by a lock on `.mutation-gate/outbreak/serve.lock`.
3. **Its state survives it as a snapshot and a journal**, both under
   `.mutation-gate/outbreak/`, `0600` in a `0700` directory:
   - `state.bin`, a versioned snapshot with a checksum in its header,
     rewritten while no client waits, at most once every
     `outbreak.serve.snapshotEvery` (`10m` by default), and at exit;
   - an append-only journal of proofs and map deltas, each record
     length-prefixed and checksummed, synced to disk at most once every
     `outbreak.serve.syncEvery` (`1s` by default).

   A record whose checksum fails ends the journal there. `ledger.json.gz` and
   `coverage.json.gz`, which the PHP gate and CI read, are written by `run`,
   `pre-push` and the daemon's exit, never by a save's round.
4. **In CI a shard holds one daemon for its life.** The shard's first
   invocation starts `outbreak serve` in the shard's workspace, with no
   socket outside `.mutation-gate`, and every later chunk's runner asks it for
   the slice of the map its files need, so the shard reads its maps once. The
   value-reader scan (ADR-0037) stays the PHP runner's. The daemon stops with
   the shard's last invocation.
5. **The PHP gate asks through an internal interface beside `MapEngine`**,
   as ADR-0119 converts beside it: `ServesState`, implemented by Outbreak's
   adapter and a fake; the static graph it keeps is asked through the
   `ReachGraph` port, which Outbreak's adapter answers from the daemon
   where it runs and from one run of the binary otherwise. Requests and answers are JSON lines under contract 1
   (ADR-0118). An answer that does not come within `outbreak.timeout`, or
   any other answer, is no answer, and the gate reads the file itself.
6. **`outbreak.serve.enabled`, `true` by default, turns it on.** It,
   `outbreak.serve.snapshotEvery` and `outbreak.serve.syncEvery` judge or
   report only and are in no key: the state answers what the files answer.
7. **One suite holds the daemon to the gate's readers.** A snapshot and
   journal decode to the ledger's proofs and the map's text exactly as the
   PHP readers read them, and a state written, journalled and read back
   writes `ledger.json.gz` and `map.json.gz` holding the text the PHP gate
   writes (their gzip bytes may differ, as ADR-0118 allows). The snapshot's
   and the journal's decoders are fuzzed.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `outbreak.serve.enabled` | `true`, `false` | `true` | judges or reports only |
| `outbreak.serve.snapshotEvery` | duration | `10m` | judges or reports only |
| `outbreak.serve.syncEvery` | duration | `1s` | judges or reports only |

### Why no verdict moves

- **The state is a cache of files the gate reads.** Every answer is checked
  against the contract suite to be what the PHP reader of the same file
  answers, and a state that does not pass its checksum is not read.
- **An identity change ends it,** as ADR-0078 decides: a new gate, lock or
  config, and the daemon refuses and exits.
- **A dead daemon costs time, not a verdict.** Its clients see the socket
  close, say why, and run the command in their own process (ADR-0078
  decision 3); a shard's chunks read their files themselves.

### Where today's path stays

- Without the binary, with `outbreak.enabled` or `outbreak.serve.enabled`
  off, or where the binary's checksum differs, `watch` and the hooks use the
  PHP `serve`, and each chunk of a shard reads its own map.
- A `serve.sock` the user does not own is refused by the same checks as
  today, and the command runs in its own process.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the state in the PHP daemon** | Each request runs in a forked child, which cannot write what it learned back into its parent; the parent would reload files to see a round's results. |
| **A database file, such as SQLite** | A dependency for an append-only log and one snapshot, where a checksummed journal does the same. |
| **An external store, such as Redis** | A service to run beside a laptop's test suite, and a socket the project does not own. |
| **No daemon in CI** | Each chunk of a pull request's shard then reads and slices its map again. |

## Consequences

- A save's round reads no ledger, map or inventory from disk and writes
  kilobytes to the journal.
- A pull request's shard reads its maps once, whatever its chunks.
- A project gains `.mutation-gate/outbreak/`, which the gate's own files
  rule leaves out of every key (ADR-0007 item 6).

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the gate's own files outside every key
- [ADR-0032](0032-a-pull-requests-shard-stops-in-chunks-of-fifteen-minutes.md): chunks
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): the value-reader scan
- [ADR-0078](0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md): the daemon, its socket and its checks
- [ADR-0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): contract 1, `outbreak.timeout`
- [ADR-0119](0119-outbreak-s-core-converts-a-run-s-php-report-into-the-gate-s-map.md): an internal interface beside `MapEngine`
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): the graph the daemon keeps
