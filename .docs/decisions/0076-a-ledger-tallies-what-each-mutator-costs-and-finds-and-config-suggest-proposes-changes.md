# ADR-0076: A ledger tallies what each mutator costs and finds, and config:suggest proposes changes

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a ledger holds beside its proofs; [0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): what `summary.json` and the dashboard show

## Context

A mutator costs runner time on every run and is worth that time only when
its mutants find gaps. The gate keeps what each mutator's newest mutants
came to for pruning (ADR-0025), but only as a window of outcomes: it knows
nothing of the time a mutator takes, of how many of its mutants an ignore
left out, or of its history past that window. A team that wants to know
which mutators to turn off, or which code needs a longer timeout, reads
logs and guesses.

## Decision

1. **The ledger tallies each mutator's mutants under each runner.** An
   optional `tally` section holds, by the runner's name and then the
   mutator's, how many mutants it made, how many were killed, survived (a
   survivor or an uncovered mutant) or were ignored (by the ignore list, the
   runner's own marker or proof of equivalence), and the runner seconds they
   took. Every run that writes a ledger and judges every mutant itself, with
   no narrowing, adds the mutants of each shard to it, each as the verdict
   judged it. A mutant's seconds are its share of the time its shard spent
   mutating, weighted as the cost model weights units, so a runner whose
   mutants carry no duration of their own, as Infection's do not, is still
   timed. Losing the section loses the report, never a verdict, so the
   ledger's format does not count it: an older ledger reads as tallying
   nothing and keeps its proofs.

2. **The ledger's learned sections are one value.** The kill history, what
   the static analysers came to, the pruning windows and the tally are
   `Learned`, which a ledger holds beside its proofs, timings, bases and
   runs.

3. **`mutation-gate mutators` reports the tally.** One row for each mutator
   of each runner, the costliest first: mutants, kill rate (killed over
   judged), survivors, seconds, seconds per kill and share of every
   mutator's time. `--format=json` is `"format": 1`, which
   `resources/mutators.schema.json` describes. It reads the default
   branch's ledger, or the `ledger.json.gz` that `--ledger` names, and runs
   and writes nothing.

4. **`summary.json` names the ten costliest mutators, and the dashboard
   shows them.** An optional `mutators` field holds each one's runner,
   name, seconds, survivors and kill rate, as the default branch's ledger
   tallied them when the publishing run began. The dashboard shows each
   repository's in a collapsed list under its row. A summary without the
   field renders as before, so the summary keeps `"format": 1`.

5. **`config:suggest` proposes changes by three rules, and never applies
   them.** The mutator rules read the tallies of the runner the config
   chooses, and share out only that runner's time.
   - An idle mutator, with at least `suggest.idle.judged` (500) mutants
     judged, at most `suggest.idle.survivors` (0) survivors and at least
     `suggest.idle.share` (5) percent of every mutator's time, costs time
     and finds nothing: `mutators.except` turns it off.
   - A noisy mutator, with at least `suggest.ignored.mutants` (50) mutants
     of which at least `suggest.ignored.share` (80) percent were ignored or
     equivalent, makes noise: `mutators.except` turns it off.
   - A slow directory, where at least `suggest.timeouts.cluster` (3)
     mutants of the newest proof of each unit ran out of time, gets a
     `paths` entry (ADR-0074) that doubles its files' `timeouts.seconds`,
     raising `timeouts.most` where it would fall below.

   Each suggestion prints with its evidence. A change the config cannot
   say, such as turning off a mutator only the runner's own set holds, is
   named with why, and left out. Then the config file, with every other
   change laid over it, prints as a unified diff in the file's own format,
   as the gate writes that format; a project with no config file sees
   `mutation-gate.json` added. It writes nothing and exits 0.

## Alternatives considered

- **Reading cost from the proofs.** A proof keeps a killed mutant as a
  kill, without its duration, and holds only the newest results, so the
  history and the time of the mutators that matter most would be lost.
- **Applying suggestions with a `--write` flag.** A suggestion rests on
  history that a change of code or tests can make stale, and a config change
  re-keys proofs; a person reads the evidence and applies the diff.
- **Publishing the whole tally.** The dashboard needs only the costliest
  few, and `summary.json` is public, read by every dashboard.

## Consequences

**A team sees what each mutator is worth.** The report and the dashboard
name the mutators that cost the most and how much they find.

**The tally grows with history.** It holds one entry for each mutator of
each runner, never one for each mutant, so a ledger grows by a few
kilobytes at most.

**Suggestions are only as good as the history.** A new project, or a
ledger without the section, tallies nothing, and `config:suggest` suggests
nothing until runs have written one.

## Related

- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): the pruning windows the tally sits beside
- [ADR-0074](0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md): the `paths` entry a slow directory gets
