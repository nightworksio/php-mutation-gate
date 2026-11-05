# ADR-0091: A file's mutants are compiled into one schema

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0004](0004-pest-and-infection-behind-one-runner-port.md): how a patched Pest serves a mutant, for a mutant its file's schema switches

## Context

pest-plugin-mutate writes each mutant as a whole file of its own,
pretty-printed, and a mutant's own run serves that file in its original's
place. Every run therefore compiles its mutant's file afresh, and nothing a
worker loaded before it forked can stand for the file, since every mutant's
file differs. On run 42036642441, shard 3, a kill cost about 0.24 s against
0.03 s for a fork alone: the child's Pest boot and its compiling dominate.

Mutant schemata (Untch, Offutt and Harrold, 1993) compile every mutant of a
file into one program, each mutant behind a switch, so the program is the
same for every mutant and only the switch differs.

## Decision

1. **`pest.schemata`, true by default, compiles each file's mutants into a
   schema** under `pest.patch`. The patched generation loop hands the gate
   each mutant's node, as the parser read it from the file, and what
   replaces it. Once the file's mutants are made, the gate writes the
   schema beside pest-plugin-mutate's copies, named by a digest of its code.
   Pest's mutant cache is not read while it is on, so every node is at hand.
   It is in key item 3 (ADR-0007).
2. **Each mutant is a switch on its original's line.** An expression becomes
   a ternary, `(switch(id) ? (mutated) : (original))`, a statement of a body
   an `if`, and an item of an array a spread, `...(switch(id) ? [] : [item])`.
   The mutated code is printed on one line, its comments left out, and the
   original keeps its own text, so no line of the file moves. A site within
   another is switched inside the other's original, and two mutants of one
   node one inside the other.
3. **A mutant that cannot be switched where it stands keeps its own copy**:
   one in a constant expression, a default, an attribute, a signature or a
   class body; a place written to, listed or taken by reference; a call's or
   an assignment's result passed as an argument, which PHP may pass by
   reference; one inside a string's interpolation; a statement that declares
   a function, a class or a label, or is replaced by one that does; an
   array item whose key an integer reads as, which a spread renumbers; and a
   change that prints over more than one line. A schema that does not read
   back as PHP switches nothing.
4. **A mutant's own run is served the schema**, its switch's id in force,
   through the same serving as a copy (ADR-0004); its records, limits and
   evidence stay keyed by its own copy, which Pest still writes for its id
   and its diff.

### Why a mutant under a schema runs as under its own copy

- **The same code on the mutant's path.** With its switch on, every site of
  it evaluates its mutated code and every other site its original; no other
  code differs, and a ternary or an `if` evaluates only the branch it takes.
- **The same lines.** Each site is written on the line its original starts
  on, so `__LINE__`, a trace's lines and a coverage map's lines are the
  original's; a mutant's own copy, pretty-printed whole, does not keep them.
- **Nothing switched that could change meaning.** The places refused in 3
  are exactly where a ternary, an `if` or a spread is not the expression,
  statement or item it replaces: a constant context, a write, a reference, a
  conditional declaration, a renumbered key.

`PatchedTest`, in the runner contract, runs every mutant Pest makes of the
library's files with schemata and with copies, with a real patched Pest, and
fails where their records differ.

## Consequences

- Every mutant of a file runs the one file, so a worker that loads the file
  before it forks can serve every mutant of it; the booted mutant worker
  builds on this.
- A mutant cache Pest kept locally is not read while schemata are on.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A switch read from the environment in every site | A call to `getenv` per evaluation is slow in a hot loop; the switch is a static int the runner sets once. |
| Splice by re-printing the whole file with php-parser's format-preserving printer | It reprints every changed ancestor, so a nested site's original loses its own text and lines move; splicing by the original's offsets keeps both. |
| Switch every site, falling back at runtime | A constant expression or a default cannot hold a call at all; the file would not compile. |
