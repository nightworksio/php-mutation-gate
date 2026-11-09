# ADR-0119: Outbreak's core converts a run's PHP report into the gate's map

**Status:** Accepted
**Date:** 2026-11-08
**Grows:** [0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): its commands, its contract suite and the PHP its gates reach, by the conversion of a run's report

## Context

`mutation-gate coverage --from=<dir>` reads php-code-coverage's PHP report
that a run of the suite left (ADR-0006) and writes the gate's map of it,
admitted as the listed suites admit it (ADR-0002, ADR-0005) and keyed for the
store. On the gate's own suite the report is 130 MB: 14,126 tests, 2,316
files and ten million places of tests on lines. On CI the command takes about
51 s.

Measured on a loaded 8-core laptop, in CPU seconds, the report's part of the
command took 14 to 17 s: unserializing it 3.4 to 4.4, building the map 2.0 to
3.8, admitting it 5.1 to 7.8, finding the holds that run nothing 1.7 to 2.4,
and encoding it 1.2 to 1.7. Two causes were the gate's own: its reading
looked each line's tests up against the whole list of tests, and its
admission spread arrays per line.
Reading each line against the list once, and deciding the admission per file
rather than per line, gives the same text byte for byte in 8 to 9 s.

What remains is PHP running the report, a serialized object graph of ten
million entries, and building objects of it. The report's format is fixed by
php-code-coverage and versioned, and its map is the gate's own text, which
ADR-0118 already has a native writer for.

## Decision

1. **The report is data, read natively.** `outbreak report outline` and
   `outbreak report convert` read php-code-coverage's report, serialization
   format 4, as bytes, never running it. They read exactly what
   php-code-coverage writes and decline anything else:
   - the envelope, `<?php // phpunit/php-code-coverage serialization format 4`,
     the nowdoc `END_OF_COVERAGE_SERIALIZATION` and `);`, with only whitespace
     after it, and no line inside the nowdoc that would end it;
   - one array of `buildInformation`, `basePath`, `codeCoverage` and
     `testResults`, each once, the build information holding what
     php-code-coverage's `Unserializer` asks of it;
   - `codeCoverage` an object of `ProcessedCodeCoverageData`, its eight
     private properties each once and no other, its function coverage empty;
   - lines keyed by positive whole numbers, each once, their tests by index;
     test ids and file names as text, each once, never a key PHP would turn
     into a number, no file name PHP sorts as a number where the report is
     unsorted, and every text valid UTF-8;
   - no reference, custom serialization, enum or other object, nothing nested
     deeper than 64 levels, and every number in PHP's grammar.

   The request names the serialization format the runner's php-code-coverage
   reads, and a report or a request in another format is declined. The
   parser is fuzzed with the binary's other inputs.
2. **The gate decides; the binary reads and writes.** The outline answers the
   map's tests in its order, the files each ran a line of, and each file as
   the report names it: `{"outbreak": 1, "outline": {"files": [<file>, …],
   "tests": [[<test>, [<place of a file>, …]], …]}}`. From it the gate decides
   what the suites admit, whether each hold from the holding suites runs what
   it holds, and each test file's entry key, as it decides them of its own map
   (`MapOutline`). The conversion's request then carries every decision and
   every value the gate encodes:

   `{"report": <file>, "format": 4, "into": <file>, "paths": {<file as the
   report names it>: <path in the project>, …}, "timed": [[<test>,
   <seconds>], …], "loads": [[[<test>, [<package>, …], <started>], …], …],
   "leaving": {<test>: [<path it stays on>, …], …}, "measured": {"commit":
   <sha>, "dirty": <bool>}, "keys": {<test file>: <digest>, …}}`

   with `leaving`, `measured` and `keys` only where the gate has them. The
   binary copies the seconds, packages, commit and keys as the gate wrote
   them, writes the map as `MapText` writes it, `loads` and all, and answers
   `{"outbreak": 1, "written": 1}`, or `"declined"` with why. It holds no gate
   policy: no path, admission, hold or key is its decision.
3. **The gate writes the map where it was asked.** The binary writes it
   gzipped into the gate's workspace, `.mutation-gate/converted/`, and the
   gate writes those bytes at `--into` through the project's directory, so a
   path that leads out of the project or through a link is refused as it is
   for the gate's own map.
4. **The engine converts beside the port, never on it.** A map engine that
   converts reports also implements `ConvertsPhpReports`, an internal
   interface: `outlining()` starts the binary on the outline and answers a
   `PendingOutline`, which the gate takes, a `MapOutline` or a `NotWritten`,
   once it needs it, and `convert()` answers a `Written` or a
   `NotWritten`. The command starts the outline before it takes its
   inventory, so the binary reads the report while the runner lists the
   suites. Its types carry the gate's own
   decisions, which no extension makes, so `MapEngine`, the public port, is
   unchanged. Outbreak's adapter and the fake implement it. A runner that
   leaves a PHP report names it (`LeavesPhpReport`): Pest with the tests it
   timed beside the report and what they loaded, PHPUnit's own coverage with
   nothing beside it, and Testo, whose coverage is XML, none. Where the runner
   names none, the engine converts none, or the binary declines, fails, runs
   past `outbreak.timeout` or answers otherwise, the gate reads the report
   itself, as it does with `outbreak.enabled` off. No setting is added: the
   conversion runs where Outbreak runs.
5. **The contract suite holds the binary to the gate's reading.** On a corpus
   of reports, a real PHPUnit run under PCOV and reports written to reach
   each edge of the format, the outline is the outline of the map the gate
   builds, and the converted map is the gate's text byte for byte, with and
   without an admission, measured and keyed. A second corpus holds a report
   the binary declines for each rule above, with the reason it gives.

## Consequences

- On the 130 MB report, the binary's conversion takes 2.6 s where PHP's read
  and encoding take 14.9 s. The whole command's CPU time falls from 38 to
  40 s to 32 to 34 s, and its peak memory from 1.1 GB to 0.5 GB; the rest is
  the inventory, the entries' keys and PHP's start.
- Without the binary the command still gains the PHP fixes: about 8 s of CPU
  less, and a peak memory of 1.1 GB where it was 1.8 GB.
- The map is the same text either way; its gzip bytes differ, as a slice's do
  (ADR-0118).
- A report in a format the binary does not read, or one another tool
  wrote, is read by php-code-coverage as before.

## Alternatives

| Alternative | Why not |
|---|---|
| **The binary admits and keys the map itself** | Admission, holds and keys are the gate's decisions, read from the suites, the runner and the store; a second copy in Rust would have to be held to the first. The outline hands the binary the result instead. |
| **One call, the gate's decisions made from the report in PHP first** | Deciding them needs which tests ran which files, which is reading the report: the work the binary is there to take. |
| **`outlining()` and `convert()` on `MapEngine`** | The port is public, and its signatures would carry the gate's decisions (`Conversion`, `MapOutline`, `MeasuredAt`, `EntryKeys`) and what they read onto the semver surface (ADR-0043). |
| **The binary writes the map at `--into`** | It would have to refuse every path the gate's directory refuses; writing into the workspace and handing the gate the bytes keeps one writer of the project's files. |
| **Unserialize in PHP and hand the binary the arrays** | Unserializing is a quarter of the time, and building the arrays to hand over costs what building the map does. |

## References

- [ADR-0002](0002-one-typed-config-from-several-formats.md): the suites a config lists, which admit a map's tests
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the holds a map keeps tests on
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the coverage run and its report
- [ADR-0043](0043-what-semver-covers-is-marked-in-the-code-and-the-port-contracts-ship-as-kits.md): the public surface a port's signatures reach
- [ADR-0115](0115-a-lock-move-reaches-what-loaded-the-package.md): what each process's tests loaded
- [ADR-0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): the binary, its contract and its settings
