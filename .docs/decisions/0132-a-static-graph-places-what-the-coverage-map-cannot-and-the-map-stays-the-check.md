# ADR-0132: A static graph places what the coverage map cannot, and the map stays the check

**Status:** Accepted
**Date:** 2026-11-10
**Grows:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change reaches, by a static graph of the sources and the tests, as `outbreak.graph` says

## Context

What a change reaches is read from the coverage map (ADR-0005): a changed
source file reaches its unit, a changed test the units its tests execute, and
changed test support the tests that name it. Where the map cannot place a
change, the rules reach everything in the package: a deleted test, no map to
read, support that runs code when it is loaded, and support named by anything
but a test or support. A change of a file that decides how the gate runs
reaches everything by design.

The gate already reads a name graph of the project: every PHP file of the
suite's sources and outside its test directories, with the files it names
(`src/Cli/Flow/NameGraph.php:15-24`). It keys a unit by the files its tests
reach through it (ADR-0007 item 10), finds a mutant's dependents for its
static check (`src/Cli/Flow/Dependents.php:19-29`), and carries a kill across
a change (ADR-0008). It names files, not calls, and it leaves out the tests.

The map is measured, so it is stale for code written since. After an edit
that adds a call, the map's covering set of the new callee does not hold the
tests that now reach it until the suite runs under coverage again. Locally,
`watch` runs a quick round per save. On the quick-start fixture a round costs
16.4 to 17.1 CPU-s, the coverage re-measure 2 to 5.6 s of wall in 10
processes of it (measured for the local loop, 2026-11-09).

## Decision

1. **Outbreak builds a static graph of the sources and the tests.**
   `outbreak graph reach` reads every PHP file of the suite's sources and its
   test files, and joins each file to every file that declares a name it
   uses, resolved by its namespace and imports (grouped, aliased and
   function imports): classes, interfaces, traits and enums named anywhere
   but after `->` or `::`, and functions called by name. A test file is a
   node, and a test reaches what its file reaches. A method called on a
   receiver joins the caller to the receiver's class where the caller names
   that class, as a type declaration does.
2. **A node the graph cannot place is reached as today.** A file holds an
   unplaced node where it calls by a name held as it runs (a variable
   function, `call_user_func` with a non-literal, a string class name, `new
   $class`), reads a class through reflection or a container by a non-literal
   key, includes a path built as it runs, or defines `__call`,
   `__callStatic`, `__get` or `__set` on a class an edge reaches. Where an
   answer of the graph would pass through such a node, the graph gives none,
   and the map's answer alone stands.
3. **The coverage map stays the check.** The graph never removes a unit or a
   test the map gives. Where the two disagree, the map's answer stands: a
   test the map shows executing a unit the graph does not join to it marks
   the graph wrong for that test, and the graph is not used for it again
   until the graph is rebuilt.
4. **A quick round adds what the map cannot know yet.** A `watch` round runs
   the map's covering tests of the edited sites first. Where the edit added a
   call the map has not measured, it then runs the tests the graph says reach
   the new callee, after the map's. A quick round's survivor stays labelled
   quick, so a missing edge shows a survivor, never a pass.
5. **`outbreak.graph` says where the graph acts:** `local`, the default,
   `narrow`, `widen` or `off`.
   - **`local`**: in quick rounds alone, as decision 4 says. `pre-push`,
     `run` and CI reach by ADR-0005's rules.
   - **`narrow`**: also in every run, where ADR-0005's rules reach
     everything in a package only because the map cannot place a change: a
     deleted test, no map to read, support that runs code when it is
     loaded, or support named by anything but a test or support. Such a
     change reaches the units the graph joins to it, and everything wherever
     its answer would pass through a node it cannot place. Every unit the map
     reaches is still reached. **`narrow` loosens**: an edge the graph misses
     leaves a unit unreached, and an unreached unit carries its result by its
     source alone (ADR-0060 rule 2) until the scheduled full run. It is an
     opt-in under ADR-0142's rules: set in bold in the reference, marked by
     `config:show`, and, where in force, noted in the verdict and the comment
     unless `suggest.looseValues` is off.
   - **`widen`**: also in every run, where a changed source file reaches,
     beside its own unit, every unit whose covering tests the graph joins to
     it, which ADR-0005 rule 6 otherwise leaves to the scheduled full run.
     It is stricter than ADR-0005's rules, and costs runs.
   - **`off`**: quick rounds run the map's covering tests alone.
6. **`outbreak.graph` decides how the gate runs,** as `reach` settings do:
   it is in no key, and a change to it carries no proof across (ADR-0007).
   Without the binary, or with `outbreak.enabled` off, every value acts as
   `off`. `outbreak serve` (ADR-0133) keeps the graph between rounds,
   updated per changed file.
7. **The graph's reader is held to the gate's name graph.** On the gate's own
   sources and on each fixture project, every file the gate's `NameGraph`
   says a file names is joined to it by the static graph, and the graph's
   parser is fuzzed, as ADR-0118 requires.

### Settings

A loose value is in bold (ADR-0142).

| Setting | Values | Default | Effect |
|---|---|---|---|
| `outbreak.graph` | `local`, **`narrow`**, `widen`, `off` | `local` | decides how the gate runs |

### Why no verdict moves by default

- **The graph adds or orders; the map decides.** Under `local`, in a quick
  round the graph adds tests after the map's and changes when a mutant is
  judged, not how. A kill it adds is a test failing on the mutant and passing
  unmutated in the round's own stream (ADR-0117), the round's control.
- **`widen` only adds reach,** so it can run more units, never carry one it
  would have run.
- **What the graph cannot see is reached as today.** Every dynamic call,
  reflection, container read and built path falls back to the map's answer.
- **A wrong graph shows itself.** A map that shows an edge the graph lacks
  turns the graph off for that test.

### The trade `narrow` offers

Where the map cannot place a change, `narrow` reaches what the graph joins
to it in place of everything, and a missed edge carries a result by its
source. A project that takes it says so in its config, every run names it,
and the scheduled full run (ADR-0005 rule 6) is its backstop. Its proof
before it ships: on the gate's own history, each change the map could not
place, reached under `narrow`, reaches every unit whose result a full run
then changed.

### Where today's path stays

- Under `local`, the default, `pre-push`, `run` and CI reach by ADR-0005's
  rules, as today.
- Without the binary, with `outbreak.enabled` off or `outbreak.graph: off`, a
  quick round runs the map's covering tests alone.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Re-measure coverage after every edit before the first round** | A coverage run per save costs seconds of wall and every core, where the graph answers in milliseconds. |
| **Extend the gate's `NameGraph` in PHP** | It is read per plan, from disk; the graph must stay hot between saves, which the daemon holds. |
| **Let the graph replace the map** | A static graph misses what PHP resolves as it runs, which the map records. |
| **Infer dependencies from the ledger's killers** | A kill history is learned from earlier code and is not a proof of the code now (ADR-0079). |

## Consequences

- The first quick round after an edit that adds a call runs the tests that
  can reach the new callee, before any re-measure.
- A project full of dynamic calls gains little, since most of its nodes fall
  back to the map.
- A project can trade a full reach it cannot place for the graph's answer,
  or pay to close a gap reach leaves to the scheduled run, each by one
  value.
- The binary carries a PHP grammar and a resolver, held to the gate's name
  graph by one suite.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the reach rules the map decides
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): item 10, the files a unit reaches by name
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): a carried kill that follows a change through the name graph
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): `watch` and `pre-push`
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): `affected`, and a mutant's dependents
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): what an unreached unit carries
- [ADR-0079](0079-a-local-run-infers-what-a-killed-mutant-subsumes.md): a kill history is no proof of the code now
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): a stream as its own control
- [ADR-0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): Outbreak's binary and its standard
- [ADR-0133](0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md): the daemon that keeps the graph
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): how a loose value is offered and named
