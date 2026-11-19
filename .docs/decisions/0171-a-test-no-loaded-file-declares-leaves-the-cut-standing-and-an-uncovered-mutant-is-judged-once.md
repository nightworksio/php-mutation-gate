# ADR-0171: A test no loaded file declares leaves the cut standing, and an uncovered mutant is judged once

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 9's cut where a test of the map is declared in no loaded test file, and the part that judges a mutant no test covers

## Context

Under `splitStream.sharding: tests` every shard takes every unit and streams
one part of the test files (ADR-0117). Each shard cuts the map's test files
into the parts by the file that declares each test's class. Where any test of
the map was declared in no test file the process loaded, no file was cut, and
the first part held every test. A split stream's pass did the same: one
unknown covering test made the pass stream the whole suite.

The gate's own full plan in run 44004701114 handed its 118 shards a map
naming 16,303 tests, one of them `P\Tests\HeldSpec`, a test of a contract
fixture that no test file of the gate's suites declares. Every shard's cut
collapsed into the first part. Shard 51, run locally on that plan and map,
reached none of the 51,938 mutants the map covers: its part held no test.

A mutant no test covers was left in every part, and each part judged it the
same way: Pest calls it uncovered, and each one on a line that is not
executable runs its trial against every test that reads its value
(ADR-0004), which knows nothing of parts. Shard 51 held 4,393 such mutants
and ran 989 trial processes, each loading several of the suite's slowest
test files; on CI they took 22 to 60 seconds each, 4 at a time. All 118
shards ran the same 989 trials, and the three shards probed were cancelled
at 15 minutes with 28 of them done.

## Decision

1. **A test no loaded file declares is left out of the cut.** It belongs to
   the first part, as before; every other test file is cut as ever, so one
   such test no longer moves every test into the first part.
2. **A split stream's pass streams only the files that declare its tests.**
   A covering test no loaded file declares is in no stream, so its mutants
   are left to their own runs, as a mutant whose covering test did not run
   always was; a pass none of whose tests a loaded file declares starts no
   stream.
3. **A mutant no test covers is judged by one part:** the part its id falls
   to, `crc32(id) mod parts`, counted from the first. Every other part
   leaves it not reached, as it leaves a mutant another part owns. Pest's
   ids are the same in every shard, so every shard agrees on the owner, and
   the parts share such mutants alike.

## Why no verdict moves

- Decision 1 changes which part streams a test the process declares; each
  test still runs in one part, and the verdict merges the parts as before.
  A test no loaded file declares cannot run in any stream, so it judged
  nothing in the first part either.
- Decision 2: a stream of the whole suite ran no test the process did not
  declare, so the mutant it covered was left to its own run already.
- Decision 3: every part judged an uncovered mutant against the whole
  suite, alike; the owner's status is the one the merge takes, and a
  shard's not-reached outcome merges away (ADR-0117).

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep the collapse and only drop the stray test from the map | Any test a map names that the suite does not declare, a renamed test in a kept map among them, would empty every part but the first again. |
| Give every uncovered mutant to the first part | One shard would run every trial; the gate's own plan has 989 of them. |
| Run trials against the part's tests only | A trial picks the tests that read a value from the whole map; cutting them by part would split one judgement across shards with nothing gained. |

## Consequences

- Each shard of a test-part run streams its own part, and the parts' uncovered
  mutants are tried once in the run, not once in every shard.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0162](0162-a-held-unit-is-judged-whole-in-one-shard.md)
