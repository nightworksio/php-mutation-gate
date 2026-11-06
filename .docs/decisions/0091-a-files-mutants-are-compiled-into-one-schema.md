# ADR-0091: A file's mutants are compiled into one schema

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0004](0004-pest-and-infection-behind-one-runner-port.md): how a patched Pest serves a mutant, for a mutant its file's schema switches; [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): what a patched Pest's worker of mutants' runs boots before it forks

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
   The loop parses each file once and hands each attempt at a mutant a copy
   of that parse, and the file is printed once for every mutant's diff, so
   making a file's mutants afresh costs one parse rather than two a mutant.
   It is in key item 3 (ADR-0007).
2. **Each mutant is a switch on its original's line.** An expression becomes
   a ternary, `(switch(file, id) ? (mutated) : (original))`, a statement of a
   body an `if`, and an item of an array a spread,
   `...(switch(file, id) ? [] : [item])`. The switch is keyed by the file, so
   a mutant in force in one file leaves every other file's schema at its
   original.
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
   and its diff. The switch is set as pest-plugin-mutate boots, before the
   run loads anything a mutant changes, by a class that loads none of the
   gate's others but the one serving copies; the gate's own run leaves both
   out of its mutation, as no mutant of either can be in place in time.
5. **`pest.bootMutants`, true by default, boots each worker of mutants'
   runs** under `pest.patch`, where `runner.workers` forks them. Before it
   forks, the worker loads Composer's autoloader, serves each file's schema
   in the file's place through an autoloader put before Composer's, boots
   Pest's kernel, and applies the PHPUnit config's PHP settings and runs its
   bootstraps, for the suites the run names, as a fresh run does up to its
   first test. It loads no test file and boots none of Pest's plugins. Each
   child boots the plugins, which its run's variables tell what to record,
   and loads the test files its run selects, as a fresh run does. A child of
   a mutant its file's schema switches is served nothing more: its switch is
   set, and the schema the worker serves is in force. Any other mutant of a
   file the worker loaded or declared from, and a mutant pinned by source of
   a file whose schema the worker serves, runs fresh, as ADR-0023's workers
   run a mutant of a file they loaded. A project with no PHPUnit config has
   each run forked unbooted; a boot that leaves a socket open has every run
   start fresh.

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

### Why a booted worker's child runs as a fresh run does

- **The same boot, once.** The worker runs what a fresh run runs before its
  first test, in the order it runs it; the child skips only what the worker
  ran, as a booted tail's child does (ADR-0075), and runs the rest itself.
- **Nothing a mutant changes is loaded before it is in force.** The worker
  loads a mutated file only as its schema, whose sites all run their
  originals until a child sets its switch; a file loaded any other way runs
  its mutants fresh, as does a mutant pinned by source, which its test reads
  through the file the schema's autoloader would pass by. The worker loads
  the class that hands each child its test databases before it serves any
  run, as the child uses it before Pest's script, so a mutant of it runs
  fresh too.
- **What records stays per child.** The plugins, which record kills,
  evidence and timings, boot in the child from its own variables, and the
  test files load in the child, so a run's selection and order are its own.

`PatchedTest`, in the runner contract, runs every mutant Pest makes of the
library's files with schemata and with copies, and from mutants' workers that
boot the project and from workers that do not, with a real patched Pest, and
fails where their records differ or a booted worker warns of a run it started
fresh.

## Consequences

- Every mutant of a file runs the one file, so a worker that loads the file
  before it forks serves every mutant of it; a child of a booted worker of
  mutants' runs starts at its first test file rather than at Pest's script.
- A bootstrap with an effect each run needs afresh, beyond PHP's state, runs
  once per worker; `pest.bootMutants: false` has each child boot as a fresh
  run does.
- A mutant cache Pest kept locally is not read while schemata are on.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| A switch read from the environment in every site | A call to `getenv` per evaluation is slow in a hot loop; the switch is a static int the runner sets once. |
| Load every test file in a mutants' worker, as a booted tail's does | A mutant's run selects only its covering tests' files; loading every file would run each file's top-level code in runs that never load it fresh. |
| Splice by re-printing the whole file with php-parser's format-preserving printer | It reprints every changed ancestor, so a nested site's original loses its own text and lines move; splicing by the original's offsets keeps both. |
| Switch every site, falling back at runtime | A constant expression or a default cannot hold a call at all; the file would not compile. |
