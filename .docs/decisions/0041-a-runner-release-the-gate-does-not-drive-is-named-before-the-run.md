# ADR-0041: A runner release the gate does not drive is named before the run, and pest-plugin-mutate follows its major

**Status:** Accepted
**Date:** 2026-10-31

## Context

What a new user meets when the installed runner is not one the gate drives,
read at source:

- **Install.** `composer.json` declares `conflict: pestphp/pest-plugin-mutate
  <5.0.2 || >5.0.2` (ADR-0004, ADR-0017). A project on any other
  pest-plugin-mutate release cannot install the gate, and Composer's message
  names only the conflict. So a project that takes a new pest-plugin-mutate
  patch cannot install the gate until a gate release takes it too.
- **The runner canary.** Every day it runs the Runner contracts against the
  newest pest-plugin-mutate, freed from the conflict, and fails naming the
  conflict to write where a release that passes is one the conflict refuses
  (ADR-0017).
- **What the Pest adapter reads.** It reads how pest-plugin-mutate runs each
  mutant, and `pest:patch` changes the plugin in place. `pest:patch`
  refuses a release whose code it does not find.
- **The PHPUnit runner.** It drives PHPUnit 13.2.0 and later, the first
  release with `--test-id-filter-file` (ADR-0023). Zero-config chooses it
  wherever PHPUnit alone is installed, and `init` writes what zero-config
  found (ADR-0002), so a project on PHPUnit 12 gets a config whose first run
  refuses.
- **What a run refuses.** Each adapter reads its runner's releases from
  Composer's `installed.json`, and a run refuses releases its runner does not
  drive before it mutates. `doctor` has no check for them (ADR-0017).

## Decision

This amends ADR-0004's item 3 and its consequences, ADR-0017's context,
items 8 and 10, and ADR-0023's item 11.

1. **pest-plugin-mutate is allowed from 5.0.2 to below the next major.**
   - `composer.json` declares `conflict: pestphp/pest-plugin-mutate <5.0.2
     || >=6.0`, and the Runner contract fixture copies it. 5.0.1 reads each
     line's covering tests as hit counts, and fails under every Pest the gate
     drives.
   - `Core\Doctor\Check\PestMutateUntested::NEWEST` holds the newest release
     the gate is tested with.

2. **`doctor` advises on a pest-plugin-mutate newer than any it is tested
   with.** Under the Pest runner, an installed release newer than `NEWEST`
   is `pest-mutate-untested`, *advice*. It names the installed release and
   `NEWEST`. A branch install is not judged.

3. **The runner canary keeps both bounds current.** For the newest
   pest-plugin-mutate, once every contract passed, it fails:
   - where the conflict refuses it, naming the conflict with its major
     raised past the release;
   - where it is newer than `NEWEST`, naming the value to write.

   So each release that passes is counted as tested by a pull request within
   a day, and ships with the next release of the gate.

4. **`doctor` names a runner release the gate does not drive.** Where the
   chosen runner's adapter refuses the releases Composer installed, `doctor`
   reports `runner-unsupported`, *will fail*, with the adapter's reason,
   before a run spends its setup.

5. **Zero-config refuses a PHPUnit the PHPUnit runner cannot drive.** Where
   it would choose the PHPUnit runner and PHPUnit is installed below 13.2.0,
   it refuses (exit code 2), naming the installed release and both ways on:
   require Infection to mutate with that PHPUnit, or require PHPUnit 13.2.0
   or later. `init` and every other command meet the same refusal until a
   config or `--runner` chooses a runner, so no config is written whose first
   run refuses.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The exact pin, as ADR-0017 has it** | Every pest-plugin-mutate patch blocks every project from installing the gate until a gate release takes it. |
| **`^5.0.2` with no record of what is tested** | A release that changes how a mutant runs reaches a project with nothing to say so. |
| **A refusal at run time on an untested release** | A plugin patch would turn a user's CI red; `doctor`'s advice informs without stopping a run that may well pass. |
| **No upper bound on the major** | A new major changes the plugin contracts the adapter reads, and a project would install it with nothing refusing. |
| **`NEWEST` read from the Runner contract fixture's lock** | The lock is not in the package, so `doctor` in a project cannot read it. |
| **Zero-config choosing Infection for an old PHPUnit** | Infection is not installed, and zero-config chooses only what is. |

## Consequences

**A pest-plugin-mutate patch installs on the day it is released.** `doctor`
says it is untested until a gate release counts it as tested.

**A project on a runner release the gate does not drive learns it from
`doctor` or from zero-config,** with what to require, before a run.

**The runner canary has two lines to move for pest-plugin-mutate,** the
conflict's major and `NEWEST`, and names each.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): zero-config, and what `init` writes
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the Pest adapter's releases, which this amends
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the runner canary
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the runner pin and `doctor`'s checks, which this amends
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the PHPUnit runner and zero-config's choice of it, which this amends
