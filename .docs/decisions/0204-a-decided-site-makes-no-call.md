# ADR-0204: A decided site makes no call

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0091](0091-a-files-mutants-are-compiled-into-one-schema.md): how a schema's sites call their switch; [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): what a split stream's parent pays at a site

## Context

Each mutant's site in a schema calls its switch at every execution
(ADR-0091): `SchemaSwitch::is()`, or, for a pure expression in a split
stream, `SchemaSwitch::value()` with two closures built each time, one for
the original and one for the mutated code (ADR-0117). A stream's parent
pays that at every site of every file it loads. That includes the sites
whose mutants another stream judges and the sites whose mutants are killed
already. Such a site can only run its original.

On a loop of 20 sites run 200,000 times, the schema took 1.2 to 1.3 s
against 0.06 s for the code unmutated.

## Decision

1. **Each switch stands behind a guard**:
   `!isset(SchemaSwitch::$live['key']) || isset(SchemaSwitch::$live['key'][id])`.
   Where the guard is false, an expression's or a statement's site runs its
   original without the call. A pure expression's site runs its original as
   written, without the call or its closures.
2. **A split stream guards each file of its plan** with the ids of its
   planned sites. It takes a site out once the site's mutant is killed. It
   lifts the guard while it boots its application, so every site it reaches
   then is recorded as before (ADR-0117).
3. **A file the guard holds no key of is unguarded**, so every call is made
   as before. That covers a mutant's own run, a forked child and any
   process outside a split stream.
4. **A pure expression's site whose original holds another site keeps the
   call without the guard's fallback.** The original's text would otherwise
   be written once more at every level of nesting.
5. **`pest.switchGuard`**, on by default, turns it off. It changes no
   output.

## Why no verdict moves

The guard is false only at a site the stream's own switch would have
answered with the original: one whose mutant is not in this stream's plan,
or is killed in it (`StreamRun::forks()` says no to both). A pure
expression's original has no side effect, so running it in place gives the
value the closure gave. Wherever the guard is true, or the file is
unguarded, the call is made as before.

## Measured

On the same loop of 20 sites (10 pure, 10 not) run 200,000 times with no
site undecided, the guarded schema took 0.40 s against 1.17 to 1.32 s
unguarded, with the code unmutated at 0.06 s. The schema's text grew from
2,566 to 5,838 bytes.

## Alternatives considered

- **One flag per site that clears itself**: the schema would need a
  variable per site, and a forked child would inherit the parent's
  flags.
- **Check the guard inside `SchemaSwitch::value()`**: the two closures
  are built before the call, and building them is most of the cost.

## Consequences

- A stream's parent runs the sites it no longer forks at close to the
  original's speed.
- The schema's text changes. No proof key reads it: schemata are made
  afresh for each run.

## Related

- [ADR-0091](0091-a-files-mutants-are-compiled-into-one-schema.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
