# ADR-0112: Every number a run goes by is a setting

**Status:** Accepted
**Date:** 2026-11-07

## Context

A team tuning the gate for its own runners met numbers it could not change: how
many proofs a ledger keeps, how long a warm worker may overrun, how much work a
chunk holds. Three adapters registered through the extension API could not be
chosen either.

## Decision

1. **Each number a run goes by is a setting whose default is today's value.** The
   configuration reference lists each with its default.

2. **`proofs.keep.killers` is the one default that moves.** It defaults to `all`,
   where it was 20,000. The byte limits still trim a ledger, and an integer sets a
   cap. A kill history that outlives the cap costs bytes, not soundness.

3. **A setting must change what happens.** The limits pest-plugin-mutate and
   Infection lay themselves stay constants: unpatched, the tool enforces its own
   rule whatever the gate says, and patched, the gate's limit replaces it. So
   there is no `pest.extra.*` and no `timeouts.infectionMultiple`.

4. **`--ignores-for` takes what a config cannot say.** `init --from` and `import`
   run before a config exists, so how long an imported ignore lasts is an option.

5. **`outputs` says what a run writes beside the reports a config lists.**
   `outputs.verdictOnly`, `outputs.automatic`, `outputs.actionReports` and
   `outputs.costs` let a team spend no runner time on what nobody reads. A re-check
   of survivors runs only where the comment or the summary reads it.

## Alternatives considered

- **A single tuning section.** A setting sits best beside the others of its subject.
- **Keeping the kill history capped by default.** It would drop what a ledger's
  byte limits have room for, and make killers-first ordering worse for nothing.

## Consequences

**`config:show` prints every default**, so a team reads what it would change from
one place.

**The key changes once.** A default written out keys a proof as the default left
unset does.
