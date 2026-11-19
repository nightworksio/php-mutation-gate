# ADR-0118: Outbreak's core vouches for and slices coverage maps

**Status:** Accepted
**Date:** 2026-11-08

## Context

On the gate's own full run, three phases on every run's critical path are
pure data work in PHP: the plan takes about 146 s, the verdict about 80 s, and
each of the 19 shards spends 78 to 102 s reading the plan's whole coverage
map, which its Pest runner reads to find the tests that run a line reading a
value a mutant changes (ADR-0006, ADR-0037).

That map, measured on the gate's own suite, is 2.4 MB gzipped and 53 MB of
JSON: 14,126 tests, 2,316 files, 53,847 lines and 9,980,446 places of tests
on those lines. Read on a loaded 8-core laptop, the fastest of several runs:

| Step | Before | After |
|---|---|---|
| inflating it within the handoff's limits (`Gzip::unpackAtMost`) | 7 to 11 s | 0.07 s |
| the careful reading (`Node::decode` and `MapFileRead`) | 2.1 to 3.4 s | the same |
| the binary vouching for the text | | 0.2 to 0.5 s |
| the map built straight from the vouched text (`VouchedRead`) | | 0.75 to 1.8 s |

Inflating joined each 4 KB step's text onto all the text before it, so it
copied the text once per step: quadratic in the map, and most of what a
shard spent reading it. Joining the steps once is linear.

The careful reading asks every one of the ten million places its shape and
maps it through the list of tests, in PHP, so that a malformed entry is
dropped rather than trusted. Building the map from the decoded text, with the
same constructors in the same order, gives the same map in about half the
time, but only where nothing in the text would have been dropped.

The plan also writes, from the whole map, each shard's map and the verdict's,
each holding every test: 19 slices of 3.5 MB of JSON and one of 53 MB, which
took PHP 5.6 s on the same laptop and a native binary 2.5 s, its compression
spread over the cores.

The verdict's own compute and its report, and the plan's reach, are the
gate's decisions, written once in PHP: moving them would write each twice. The
ledger is read whole into PHP objects, so decoding it elsewhere saves nothing.

## Decision

1. **Inflating a gzip stream within a limit is linear.** Each step's text is
   kept apart and joined once, at the end, and the limit is held to the
   length counted so far. This is PHP's, and every reader of a map, a ledger
   or a kept companion gains it, with or without the binary.
2. **Outbreak's core is an optional binary, `outbreak`,** built from the Rust
   workspace under `outbreak/`. It takes over the two parts of the coverage
   map's work whose formats are fixed and whose output the gate already
   writes: vouching for a map's text, and slicing a whole map. Every other
   phase stays in PHP.
   - `outbreak.enabled`, `true` by default: the gate runs the binary where one
     fits the machine and passes its checksum.
   - `outbreak.binary`, none by default: a binary to run in place of the
     shipped one, from the project's root where relative. It is asked no
     checksum, only that it speak the contract.
   - `outbreak.timeout`, `5m` by default: how long one run of the binary may
     take before it is stopped and the gate does the work in PHP.
   - `outbreak.threads`, none by default: how many threads the binary writes
     a plan's maps on; every core the machine has where none is set. Both are
     numbers a run goes by, so both are settings (ADR-0112).
   - No key changes a result, since the output is the gate's own either way,
     so none is in a proof's key or decides how the gate runs: they judge or
     report only, as the `shards` keys do. The `Shards` part holds them, as it
     holds what a plan hands its shards, and the Pest runner is handed them
     beneath its options, for the whole map it reads in each shard.
3. **The contract is 1.** Each command reads its input and writes one line of
   JSON holding `"outbreak": 1`. A run that ends with another code, runs past
   `outbreak.timeout`, or answers otherwise is no answer, and the gate does
   the work in PHP.
   - `outbreak contract` says the contract, and a binary that speaks another
     is not used.
   - `outbreak map vouch` reads a map's text and answers `"vouched": true`, or
     `false` with why.
   - `outbreak map split [--threads <n>]` reads `{"whole": <path>, "slices":
     [{"into": <path>, "files": [<path>, …]}, …]}`, writes each slice gzipped,
     beside its place and moved into it, on `n` threads or one per core, and
     answers `"written": <count>`, or `"declined"` with why. It inflates no
     whole map past 1 GiB: a map the gate wrote is held whole in the plan's
     memory first, so the bound guards the binary, not a run, and is no
     setting.
4. **A vouched text holds nothing the careful reading drops, and nothing two
   readings could take apart.** The binary vouches only for valid UTF-8 JSON
   whose top is an object of `format` 1, `tests` and `files`, and optionally
   `methods`, `loads`, `commit`, `dirty` and `keys`, each once, where:
   - every test is an object of a text `id` and, where it has one, a
     `seconds` number with no minus sign, and no id is listed twice;
   - every line is a key of digits with no leading nought, at most PHP's
     largest integer, holding a list of whole numbers, each the place of a
     listed test;
   - every method is an object of a non-empty `name` and whole `start` and
     `end`, `start` one or more and `end` no less;
   - `loads` (ADR-0115) is a list of processes, each a list of its tests, each
     exactly a whole number, the place of a listed test, a list of texts, the
     packages it loaded, and `true` or `false`, whether it started processes;
   - no object writes a key twice, and nothing nests deeper than 64 levels.

   The gate builds a vouched text straight from its decoded JSON
   (`VouchedRead`), with the calls the careful reading makes in its order, so
   it is the map the careful reading builds. Any other text is read
   carefully.
5. **A slice is the gate's own text.** The binary slices only a whole map the
   gate wrote: vouched, with no whitespace between its tokens, and every path
   as `Path::of()` writes it. It copies the map's own bytes, `tests` and
   `loads` whole and the lines and methods of the slice's files in the map's
   order, as `MapText::onlyFor()->written()` writes them, a list where PHP's
   keys of the kept paths are 0, 1, 2 and on. The gzip bytes differ from
   PHP's; the text they hold does not.
6. **The gate asks through a port, `MapEngine`.** Its `vouches()` answers a
   `MapVouch`, and its `split()` a `Written` or a `NotWritten` saying why. The
   adapter, `Adapter\Outbreak\Outbreak`, runs the binary, and with none, or
   where the binary fails, vouches for nothing and splits nothing. The plan's
   handoff asks it to write the shards' and the verdict's maps after the
   gate wrote the whole one, and writes them itself where it declines. Every
   handed or kept map the flows read, and every map the Pest runner reads,
   the whole map in each shard among them, is read with its vouch.
7. **The package ships the binary for each platform it builds for.**
   `linux-x86_64` and `linux-aarch64`, static against musl, `darwin-x86_64`,
   `darwin-aarch64` and `windows-x86_64`, each under
   `resources/outbreak/<platform>/`, and their SHA-256 in
   `resources/outbreak/SHA256SUMS`. The release's draft builds them and lays
   them in the release pull request, so the tagged commit Composer installs
   holds them. The gate uses the one for the machine it runs on only where its
   checksum is the one listed, and makes it runnable where unpacking left it
   not. A machine none is built for, a binary missing or a checksum that
   differs has none, and the gate does the work in PHP. The PHAR carries no
   binary: it runs one only where `outbreak.binary` names it.
8. **The Rust workspace is held to herald's standard.** `unsafe_code` is
   forbidden and `missing_docs` denied; clippy runs pedantic on every target,
   with `unwrap`, `expect`, `panic`, indexing, `todo` and `unimplemented`
   denied and every warning an error; rustfmt, cargo-deny and cargo-audit
   pass, and so does the oldest toolchain `rust-version` names. Every line is
   covered (llvm-cov) and every mutant caught (cargo-mutants, in four
   shards). Each parser is fuzzed: a map's text, a split request, and a slice,
   which must itself be a map the slicer vouches for and slices again. The
   map crate reads no file, clock, argument or variable: the binary hands it
   every byte.
9. **One suite holds the binary to the gate's PHP.** The `MapEngine` contract
   suite runs against the fake and against the binary `OUTBREAK_BINARY` names:
   a text it vouches for is built into the map the careful reading builds, it
   vouches for no text the careful reading drops an entry of, and each slice
   it writes holds the text the gate writes.
10. **Its jobs run where a change reaches them, and one check always
   reports.** `outbreak.yml` holds Outbreak's gates (the Rust gates and the
   boundary, the contract suite against the built binary) and its builds
   (the binary for every platform). ci.yml calls it on every run, with the
   gates where `what changed` lists `outbreak`, for a pull request that
   changes the Rust tree, its workflow or the PHP the binary talks to (the
   port, the adapter, `Core/Coverage` and their tests), and the builds where
   it says `builds`, for the Rust tree or its workflow. A change to the Rust
   tree alone runs no PHP gate. Both run on every push to main and every
   night. Its last job, `outbreak / passed`, passes where every job it was
   asked for passed and is skipped where it was asked for none, so it reports
   on every pull request and is the check main requires: the scope decides
   through inputs and `if:`, never a paths filter, which would leave a
   required check unreported. release.yml calls it for the builds alone.

## Consequences

- A shard reads the whole map in a few seconds rather than most of a minute:
  inflating it is linear everywhere, and where the binary is shipped for its
  runner, the map is built without the ten million checks. The plan's handoff
  writes its slices natively, and every other map the gate reads is vouched
  for too.
- Without the binary, or with `outbreak.enabled` off, the gate runs as it did,
  and its output is the same either way.
- A release's commit holds a binary for each platform, about half a megabyte
  each.
- A map another tool wrote, or one with an entry the careful reading drops,
  is read as carefully as before.

## Alternatives

| Alternative | Why not |
|---|---|
| **The binary decodes the map and hands PHP the result** | PHP still builds every object, which is where the time goes; and the binary's dropping rules would have to match PHP's for every malformed entry. Vouching asks it only for a subset where no entry is dropped. |
| **A faster careful reading in PHP** | The checks are per place, ten million of them, and in PHP each costs what the whole build costs elsewhere. |
| **The binary takes the verdict or the report** | They are the gate's decisions, written once in PHP; a second copy in Rust would have to be held to the first by a suite as large as the gate's. |
| **Download the binary at install time** | A Composer package that fetches code as it installs runs what its lock does not pin; the shipped binary is checked against the list its release committed. |
| **An extension loaded into PHP** | It is the next part of Outbreak, `outbreak.so`, and needs the Zend boundary; the binary needs none, and runs wherever PHP can start a program. |

## References

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the plan hands each shard its map and the whole map
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the verdict's map, for the kill matrix
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): why a shard reads the whole map
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): every CI job and its entry in gates.json
- [ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md): why the timeout and the threads are settings
- [ADR-0115](0115-a-lock-move-reaches-what-loaded-the-package.md): what each process's tests loaded, which a map holds as `loads`
