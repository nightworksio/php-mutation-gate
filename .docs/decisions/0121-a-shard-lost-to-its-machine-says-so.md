# ADR-0121: A shard holds one codebase scan, and a shard lost to its machine says so

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the verdict names how each shard that left no result ended; [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): each progress line says what the machine has left

## Context

A pull request that reaches every unit runs each shard in chunks of about
fifteen minutes (ADR-0032). Under `pest.patch`, each chunk's run scans the
codebase for the tests that read the values its files declare: every test
file, and every file the whole map covers. The Pest runner kept every scan it
made for the life of the process, by the files of the run it was made for, so
each chunk added one more scan of the whole codebase to the gate's own
process.

A shard of this repository's full run, measured on GitHub's hosted
`ubuntu-24.04-arm` and `ubuntu-24.04` runners (16 GiB of memory, 3 GiB of
swap, 4 cores), grew from 1.3 GiB to 14.5 GiB in its first fifteen minutes,
about half a gibibyte a chunk. Memory ran out, swap filled, and GitHub stopped the
runner: the job's log ends with "The runner has received a shutdown signal".
One shard lost its runner outright instead, "The hosted runner lost
communication with the server". The same shard run as one invocation, as on
`main`, stayed under 2 GiB for 80 minutes. Disk was never short: over 80 GiB
stayed free.

The verdict then said only that the shards "left no result", as a shard that
crashed, was cancelled or never started does. A shard that the matrix's
fail-fast cancelled once another failed read the same as one whose machine
ran out of memory, and the reason was in each job's log, if anywhere.

## Decision

1. **A Pest runner holds one scan of the codebase at a time.** A run's values
   are read from its scan before it runs, and its trials read the same scan
   after it; a run of other files scans afresh, and its scan takes the
   earlier one's place. A shard's memory no longer grows with its chunks.
2. **Each progress line says what the machine has left**: the memory a
   process can still take of all there is, the swap used, and the disk free
   under the project, in gigabytes, read from Linux's `/proc/meminfo` and the
   file system as the line is said. A system that gives no `/proc/meminfo`
   leaves memory and swap out. The reading is one small file and one
   `statfs` a minute, so it is not a setting.
3. **The verdict names how each shard that left no result ended**, where the
   CI says so in that result's place, `.mutation-gate/results/<id>.ended.json`:
   its runner received a shutdown signal, its runner lost contact with the
   CI, the CI cancelled it once another shard failed (and which), the CI
   cancelled it, it ran past its time limit, or it failed; with the job's
   address. The verdict is still *cannot judge*: the CI's word names the
   cause, and never stands for a result. The reusable workflow's verdict job
   writes the file from the attempt's jobs and the error lines of the logs of
   those that were cancelled or failed, which its token already reads.

## Alternatives considered

- **Fewer processes where memory is short.** The processes the gate starts
  held under 1.2 GiB each throughout; the gate's own process grew. Fewer of
  them would have slowed every shard and still run out.
- **A larger runner.** The scans grow with the chunks, so a larger machine
  only fails later.
- **Keying each scan by the files outside the map**, so one scan serves every
  chunk. A run's scan depends on the test cases its narrowing names as well,
  and one scan at a time already bounds the memory.
- **Reading annotations for the cause.** GitHub writes no annotation for a
  runner's shutdown, and reading annotations needs `checks: read`, which
  every caller of the reusable workflow would then have to grant.

## Consequences

- A full run on a pull request runs each shard in about the memory a run on
  `main` does.
- A shard lost to its machine, or cancelled after another, is named as such
  in the verdict, and its job's log shows what the machine had left a minute
  at a time.
- Each chunk after a run of other files scans the codebase again, as every
  chunk already did.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the verdict reads the result files
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the progress lines
- [ADR-0032](0032-a-pull-requests-shard-stops-in-chunks-of-fifteen-minutes.md): the chunks
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): the scan for the tests that read a value
- [ADR-0103](0103-a-doomed-shard-fails-fast.md): the matrix's fail-fast
