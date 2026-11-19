# ADR-0154: The gate's hot paths are counted by Tally in every pull request

**Status:** Accepted
**Date:** 2026-11-15
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its table of the gates the package holds itself to, by one row

## Context

The gate's own work grows with the project it judges: the mutants of each
unit, the plan and its cut, the coverage map and the ledgers it reads and
writes, and the reports it renders. Unit tests built on
`tests/Support/Growth.php` keep the worst of it linear by comparing CPU time
at two sizes. CPU time on a shared runner moves by more than most
regressions, so those tests only tell linear from quadratic. A change that
makes a hot path twice as slow passes them.

[Tally](https://github.com/phpstan/tally) counts the instructions, cycles and
memory of a benchmark under CodSpeed's CPU simulation, which is built on
Valgrind. With the same PHP build, the counts barely move from run to run, so
expected numbers are committed beside each benchmark and checked like a
test's expected output. Tally runs only on Linux, x86_64 or aarch64, and
needs no account; it uploads nothing.

## Decision

1. **`phpstan/tally` is a dev dependency of the root project.** It requires
   nothing but PHP 8.2, so it shares the root `composer.lock` like every
   other tool the package uses; the package keeps no Composer project per
   tool.
2. **Benchmarks live in `tests/Bench`, mirroring `src`,** each class in a
   `*Bench.php` file named like it. `tally.php` at the root names the
   directory and runs every case with `memory_limit=-1`. A benchmark builds
   its input in its data provider, so the counted call is the work alone.
3. **Growth is held by a complexity class, size by expected numbers.** Where
   the cost must not grow faster than its input, the benchmark runs at three
   sizes and states the class (`#[Linear]`, `#[Linearithmic]`), with Tally's
   ±10 % growth tolerance. Elsewhere it holds `#[Expected]` numbers, with
   Tally's default tolerance: ±2 % instructions, ±5 % cycles but at least
   2,000, ±5 % memory. The first benchmarks:

   | Benchmark | What it counts | Held to |
   |---|---|---|
   | `Mutator/Engine/EngineBench` | the default mutators making a file's mutants and counting its sites | linear in its methods |
   | `Core/Plan/CutBench` | cutting weighed units into shards, by size and to a target | linear in the units |
   | `Core/Coverage/CoverageMapBench` | joining two maps, writing a map's text | linear in the lines |
   | `Core/Proof/LedgerJsonBench` | writing a ledger's text, decoding a ledger | linear in the proofs, which come from a few runs: retention orders the instants of their runs, never the proofs |
   | `Core/Report/ReportBench` | the JSON report and the sticky comment of a verdict | linear in the mutants |
   | `Adapter/Outbreak/ReportAnswerBench` | reading the outline outbreak.so answers of a coverage report | linear in the tests |

4. **The `tally` job runs `tally run` on every pull request** whose change
   reaches the program, as `checks` does: tally.yml, which ci.yml calls, so
   its check is `tally / tally`. It runs on
   `ubuntu-24.04`, a fixed image, with PHP 8.5 and `posix`, and keeps its
   results as the `tally-results` artifact. A case that is slower than its
   numbers or its class, or is not measured, fails the job. A new case passes
   until its numbers are accepted.
5. **Expected numbers come from CI, never from a laptop.** The counts depend
   on the PHP build, its patch release, its extensions and the runner image.
   A pull request that adds or changes a benchmark takes its numbers from its
   own run: `gh run download <run> -p 'tally-*' -D tmp/tally`, then
   `vendor/bin/tally accept tmp/tally`.
6. **The job is a required check** once every case holds its numbers.
7. ADR-0011's table of the toolchain gains this row:

   | Piece | In the in-house project | Here | Why |
   |---|---|---|---|
   | Tally | none | **Added** | The gate's hot paths are counted on every pull request, so a slowdown is caught by its instructions, not guessed from noisy times. |

## Alternatives considered

| Alternative | Why not |
|---|---|
| A Composer project of its own under `tools/tally` | Tally requires nothing that conflicts with the root's dependencies, and the package keeps no tool apart. |
| More `Growth` tests | They compare CPU time, which tells only a class of growth apart, and only roughly. |
| phpbench with timings | Timings on a shared runner move by more than the regressions worth catching. |
| Expected numbers measured locally | Tally does not run on macOS, and counts from another build or image differ. |

## Consequences

- Updating PHP's patch release in CI, or the runner image, moves the counts
  of many cases at once; their numbers are accepted again from that run.
- The `Growth` unit tests stay: they run everywhere the suite does,
  including on a laptop.

## Related

- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)
