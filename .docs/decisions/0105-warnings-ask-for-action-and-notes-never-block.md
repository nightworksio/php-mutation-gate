# ADR-0105: Warnings ask for action, and notes never block

**Status:** Accepted
**Date:** 2026-11-07
**Amends:** [0102](0102-one-comment-section-shapes-every-pull-request-comment.md): the comment's sections

## Context

The gate raised everything it had to say as a warning: a config that names
what does not exist, and a warm worker's mutants that ran fresh. A team could
act on the first and on nothing in the second. A release is tagged only when
the gate prints no error and no warning, and a section that fills on every run
gives that rule nothing to read.

## Decision

1. **A warning asks someone to act.** A clean run prints no `Warnings` section.
   Warnings stay for what a config or a project can change: a ledger that
   cannot be read, an expired or redundant ignore, an owner or a CODEOWNERS
   line the gate cannot use, survivors left unchecked, an unpatched Infection,
   a mutator set that is not installed, a shard target that cannot be met, a
   tree held to no floor, ignore markers the config lets through, and opcache
   missing for equivalence checks.

2. **A note asks nothing of anyone, and never blocks.** Notes print under a
   `Notes` section of their own: what a warm worker did instead, why kills did
   not carry, why a shard had no kill history, why the kill matrix holds killers
   alone, why tests go by their ids, suites whose tests the gate cannot run,
   files that nothing holds, and what stopping a doomed run would have saved.

3. **Every report keeps them apart.** The JSON report and a shard's result hold
   `warnings` and `notes`. The console, the summary, the HTML report and the
   digest each say them separately. The pull request comment has a `notes`
   section, folded by default, beside `problems`. GitHub annotations raise a
   warning at warning level, in the room the warned mutants leave, and a note
   as a notice.

## Alternatives considered

- **A setting that silences chosen warnings.** A team would turn off what
  it should act on, and the release rule would read nothing.
- **Dropping the notes.** They explain why a run took the time it took, which
  is what a reader of a slow run asks.
- **A separate `Note` type through the whole pipeline.** Each step that hands
  warnings along would take two values and say nothing more.

## Consequences

**The release rule reads something real:** a `Warnings` section means someone
has work to do.

**A note cannot fail anything,** and neither can a warning: only failures and
what the gate cannot judge decide a verdict.
