# ADR-0150: A mutant reaches the PHP processes its tests start

**Status:** Accepted
**Date:** 2026-11-14

## Context

pest-plugin-mutate serves a mutant inside the process that runs its tests:
its stream wrapper, or the gate's autoloaded copy (ServedClasses), puts the
mutated file in the original's place there. A PHP process a test starts, a
command-line tool it runs, a worker it spawns, a fixture project's runner,
loads the file from disk. Code that runs only in such a process is judged
by the test that starts it once the map names that test (ADR-0135), and the
mutant always survives there: the process never ran it. A survivor of that
kind says nothing about the test.

The variables a mutant's own run is told its mutant in reach those
processes already (PlannedMutants::variablesOf), but nothing in them reads
the variables, and a gate those processes run withholds every
`PEST_MUTATION_*` and `MUTATION_GATE_*` variable from what it starts
(Withheld::otherRuns).

## Decision

1. **`pest.childProcesses`, `serve` by default, serves a mutant to every
   PHP process its tests start.** `off` leaves those processes running the
   file on disk. It affects results, so it is in every proof's key
   (ADR-0007). It takes effect in a patched run (`pest.patch`), whose
   plugin tells the processes of the mutant, of a project the gate places
   mutants in (ADR-0023), whose wrapper the script registers; an unpatched
   run serves none.
2. **Every PHP process of the run scans one more ini file.** A patched
   run's command appends to `PHP_INI_SCAN_DIR` a directory beside its
   results, one for each process of the gate, holding an ini file whose
   `auto_prepend_file` is a script, ServedChildren. The script loads
   ServedClasses, SchemaSwitch and the gate's file wrapper by path, before
   any autoloader, reads no class an autoloader would load, and leaves no
   variable behind; then it prepends any file the PHP that runs the gate
   prepends already.
   The directory is removed when the run is done.
3. **A process that put a mutant in place tells the processes it starts.**
   The plugin's boot in a mutant's own run, and a stream's child as it
   forks into its mutant (ADR-0117), set `MUTANT_SERVED_TO_CHILDREN` to the
   original, its mutated copy, and the schema and switch the copy is served
   by (ADR-0091). No run withholds it, so it reaches a gate the test runs
   and what that gate starts.
4. **A fresh run's processes are served too.** A fresh run (ADR-0148)
   scans the same ini file; its own process prepends the override on its
   command line, which wins, and the processes its tests start serve the
   file the gate names to it in `MUTATION_GATE_MUTANT` and
   `MUTATION_GATE_MUTATED`.
5. **The script serves only what it was told.** In a process started with
   that variable it puts the schema's switch in force and serves the
   schema, or the copy, through the wrapper; in every other process,
   the mutant's own run among them, which was started before the variable
   was set, it serves nothing.
6. **A split stream's test that starts a PHP process is judged by its
   mutants' own runs.** A process the stream's parent starts runs no
   mutant, as none is in force there, so a child forked after it, or a
   mutant whose site only it reached, would be judged against what that
   process did unmutated. The parent names a file in
   `MUTATION_GATE_SPAWNED` as each test begins; the script marks it in
   every process the test starts. Once it is marked, the test forks no
   more and falls back, as a test holding a descriptor does, and every
   mutant it covers is judged by its own run, which serves the processes.
   A stream's child names none, as the processes it starts are served its
   mutant.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `pest.childProcesses` | `serve`, `off` | `serve` | affects results; keyed |

## Why no verdict moves

- **A kill is still a test failing.** A child that runs the mutant can make
  its test fail, and then only because the mutant changed what the child
  did.
- **Nothing is served where it was not before, unasked.** The script serves
  only in a process a mutant's run started, and only the file that run
  serves.
- **`off` is what every run did before.** Under `off` no ini file is
  scanned and no variable is read.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Hold code that runs only in children out of the verdict | A survivor that no test can kill passes the gate by exemption, and the code is never judged. |
| Write the mutant to disk in the original's place | Every process of the machine would run it, the run's own others among them, and a crash leaves the tree mutated. |
| Pass `-d auto_prepend_file` to each child | The gate does not start those processes; only the environment reaches them. |

## Consequences

- A test that runs code in a process of its own judges that code's
  mutants by what the process does.
- A child that sets its own `auto_prepend_file` on its command line, or
  starts PHP with an empty environment, runs the file on disk.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)
- [ADR-0091](0091-a-files-mutants-are-compiled-into-one-schema.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md)
- [ADR-0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md)
