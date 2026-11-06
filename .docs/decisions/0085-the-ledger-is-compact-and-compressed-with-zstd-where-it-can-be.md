# ADR-0085: The ledger is compact, and compressed with zstd where it can be

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): item 3, the ledger's file, for format 5
**Amends:** [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): where the kill history lives

## Context

The gate's own ledger, format 4, measured 9.63 MB as JSON and 2.72 MB
gzipped. Every plan and every shard downloads it, and the verdict writes it
again. Most of its bytes repeat what the file already holds or could do
without:

- a proof's base is a 64-character digest that the `bases` section already
  holds;
- the kill history repeats, as a key, the 12-character id of every mutant it
  ranks, and each of those mutants sits in a kept proof;
- a mutant killed by static analysis keeps its diff and the analyser's whole
  finding, though a mutant killed by a test keeps neither;
- a test id spells its class again for every method;
- durations keep sixteen digits.

zstd packs the same JSON at about half gzip's size, and unpacks faster.

## Decision

1. **The ledger's format is 5.**
   - A proof names its base by its index among the ledger's `bases`, or by its
     digest where the bases do not hold it.
   - The `tests` section holds each test under its class, the part of its id
     before the first `::`, as `{class: [rest, …]}`, the classes in the order
     first named. A test's index is its place in that order. An id with no
     class before a `::` sits whole under `""`.
   - The `killers` section places the ranking of each mutant a kept proof
     records as `[proof, place, pairs]`, by the proof's index among the
     ledger's proofs and the mutant's among that proof's records, newest last.
     A ranking whose mutant no kept proof records keeps its id as the key, as
     before. Retention keeps rankings only for mutants that kept proofs hold,
     so in practice every ranking is placed.
   - A mutant killed by static analysis keeps no diff, and keeps only the
     analyser and the code of its rejection. Reading one, the finding sits in
     the mutant's own file and says nothing more.
   - A mutant's `seconds` and a timing's `seconds` are kept to the
     millisecond. A limit, and the time the unmutated code needs, stay exact,
     since learning divides by them.
   - Mutant ids stay in every record. The id is read after the code it names
     has moved: by the kill history, by `show` and `explain`, and by pruning's
     carry. Without the source at the proof's content it could not be made
     again.
   - Proof keys stay 256 bits: a key is all that ties a result to its code.
2. **A ledger of format 4 is still read.** Its tests list, its bases by
   digest and its kill history by id read as they always did. The next run
   writes format 5. Proofs of format 4 carry no key of today's gate (ADR-0084),
   but the timings, kill history and learned limits they sit beside do.
3. **`proofs.compression` sets how a ledger's file is compressed:** `auto`
   (the default), `gzip` or `zstd`. `auto` writes zstd where this machine
   offers it and gzip where it does not. zstd is PHP's zstd extension where it
   is loaded, at a version that reads a stream a step at a time (0.13 or later),
   and otherwise the `zstd` program on the path. `zstd` named where neither is
   here leaves the ledger unwritten, and the run says why.
4. **`proofs.compressionLevel` sets the level,** 1 to 19, 9 by default. gzip
   packs at 9 at most.
5. **Reading tells gzip from zstd by a file's first bytes,** whatever wrote
   it. A reader that meets zstd with neither the extension nor the program
   says so plainly, and names what to install or set. Unpacking stops soon
   past the read limit, either way. The file keeps its name, `ledger.json.gz`,
   and every store keeps it where it did.
6. **A delivery's staged ledger stays gzip,** so `deliver` reads it whatever
   its machine offers. The store it delivers to writes it as the config says.
7. **Both keys judge or report only.** They change the bytes of the file,
   never what it holds.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Drop the mutant ids, regenerating them in order from each proof's key** | The order is fixed for a key, but the ids are read where the source is gone: the kill history puts a unit's earlier killers first after the unit changes, `show` finds a killed mutant by id, and pruning matches a proof's kills to the run's. Making them again would also mean running the runner's mutator at plan time for every carried unit. |
| **128-bit proof keys** | A birthday collision would then cost about 2⁶⁴ hashes, within reach of a GPU farm, and two colliding sources would let a clean result carry over a source with surviving mutants. They save 3.6% gzipped. |
| **zstd at level 19 by default** | It saves 0.17 MB on every read, against about 3 s more on every write; level 9 is the default, and the level is a setting. |
| **The program alone** | A machine with the extension but no program would get gzip. |

## Consequences

Measured on the gate's own ledger, 4,805 proofs, read and written by this
gate on an Apple M1 Pro:

| | Bytes | Write | Read |
|---|---|---|---|
| format 4, gzip | 2,719,512 | 2.7 s | 3.1 s |
| format 5, gzip at 9 | 2,196,944 (−19%) | 1.8 s | 1.4 s |
| format 5, zstd at 9 | 1,269,808 (−53%) | 1.2 s | 2.0 s |
| format 5, zstd at 19 | 1,093,928 (−60%) | 4.3 s | 1.6 s |

- Each plan and shard downloads half as many bytes where zstd is here.
- A ranking a dropped, malformed proof placed is dropped with it.
- The gate's own CI installs the zstd extension, so both of its paths are
  covered and their mutants tested.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the kill history and the read limits
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): the proof key's format
