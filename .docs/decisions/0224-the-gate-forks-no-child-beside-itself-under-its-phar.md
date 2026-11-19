# ADR-0224: The gate forks no child beside itself under its PHAR

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md): a shard run from the PHAR leaves no progress; [0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md): the warm-up runs in the plan's own process under the PHAR; [0169](0169-the-whole-map-is-written-beside-the-readying.md): the whole map is written by the plan itself under the PHAR

## Context

Three steps fork a child from the gate's own process and let it go on
loading the gate's classes while the parent does too: the analyser's
warm-up as the plan is made (ADR-0164), the whole map written beside the
readying (ADR-0169), and a CI shard's progress (ADR-0157). Run from the
gate's PHAR, parent and child share the one open archive PHP read the
gate from, and its position in it. A class each loads at the same moment
reads from the other's position: the lowest-PHP run of the PHAR's smoke
suite failed including `src/Cli/Flow/HandedWhole.php` on a crc32 mismatch.

The other children the gate forks are the Pest runner's stream children
(ADR-0117), and the PHAR refuses the Pest runner (ADR-0051), so none of
them is forked from the archive. The native and Infection runners load the
archive in PHP processes of their own (`OwnArchive`), each opening it
anew, and share nothing.

## Decision

1. **`Forking::besideTheGate()`** holds where this PHP can fork and the
   gate does not run from its PHAR.
2. **The warm-up, the whole map's write and a shard's progress** fork only
   where it holds. Under the PHAR the plan warms the analyser up and
   writes the whole map in its own process, and a shard leaves no
   progress.

## Consequences

- Under the PHAR the plan takes as long as the warm-up and the whole map's
  write besides its other steps, rather than overlapping them, as the
  plan's step record shows (ADR-0220).
- Under the PHAR a lost shard leaves no progress to say how far it got;
  `shards.progress` leaves none there.
- All three fork again under the PHAR once the parent loads, before
  forking, every class and file the child reads from the archive, so that
  no child reads the archive at all.

## Related

- [ADR-0051](0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md)
- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
- [ADR-0169](0169-the-whole-map-is-written-beside-the-readying.md)
