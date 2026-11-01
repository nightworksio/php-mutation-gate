# ADR-0030: A lock file decides how the gate runs only where it moved a package the config names

**Status:** Accepted
**Date:** 2026-10-20
**Narrows:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its lock file that reaches everything
**Narrows:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): its lock file that reaches every carried kill

## Context

ADR-0005, rule 1 makes each package's `composer.lock` a file that
decides how the gate runs: "a dependency that moves changes what every mutant
runs against, and no coverage map shows it". ADR-0008 lets no kill
carried across commits stand past a change to one. So every change to a lock
reaches every unit.

In this repository, 5 of the 10 Dependabot pull requests between 2026-10-14
and 2026-10-20 changed the root `composer.lock`. Each of them mutated all
34,035 mutants again, about 31.7 runner-hours a run. One moved only
`infection/infection`, which the gate's own run never loads.

Most packages a lock pins cannot change a verdict. What can is the runner and
whatever makes, runs and judges the mutants: Pest and its plugins, PHPUnit and
its components, the parser, and the analyser with the rules it loads.

## Decision

1. **`reach.lockPackages` names the packages whose move decides.** It is a
   list of Composer package names, `vendor/name`, where `*` stands for any run
   of characters within the vendor or the name, such as `pestphp/*`. A config
   that leaves it out decides by every package, so nothing changes for a
   project that does not set it. The setting decides how the gate runs
   (ADR-0005), and its lists from several layers add up, as
   `reach.everything`'s do.
2. **A change to a package's lock in its place decides only where it moved a
   package the setting names.** The lock is read at the base and now. A
   package has moved where its entry under `packages` or `packages-dev` was
   added, removed, or changed in any field. The change decides where:
   - a moved package matches an entry of the setting;
   - a moved package is one this repository ships: the gate or a plugin of
     `plugins/`, which make, run and judge every mutant, named or not, so an
     upgrade of the gate never leaves a result the older version produced
     carried;
   - any key besides those two lists, `content-hash` and `_readme` moved, such
     as `platform`, `aliases` or `minimum-stability`;
   - either version cannot be read as a lock.

   Otherwise it decides alike, as a config whose change moved no setting that
   decides does, and reaches nothing by itself. A lock that is added, deleted
   or renamed decides as before.
3. **A carried kill follows the same rule.** ADR-0008 reads the
   lock at the commit a kill was judged at and now, and a lock that decides
   nothing is followed by the words of its name, as any other file that is not
   PHP is.
4. **`vendor/composer/installed.json` and the proof key do not change.** A
   committed `installed.json` still decides whole. A proof's key still holds
   its digest (ADR-0007), so the scheduled full run (ADR-0005)
   re-proves every unit after any move of a dependency. That run bounds what a
   narrowed reach could miss.
5. **The gate's own config lists the packages that decide its verdict**, each
   with its reason in a test that fails where the list and the reasons part.

This supersedes ADR-0005, rule 1 for `composer.lock`, and ADR-0008 for "a
package's `composer.lock`", where the setting is set.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Every lock change reaches everything (ADR-0005 as it stood)** | Each dependency bump of a package that cannot change a verdict costs a full run. |
| **A fixed list in the gate** | Which packages decide is the project's: its runner, its analyser and the rules its analyser loads. |
| **Also narrow the proof key** | The key is what the scheduled full run re-proves by (ADR-0007). It is the bound on a narrowed reach, so it keeps every package. |

## Consequences

- A lock change that moves only packages outside the setting reaches nothing,
  and the run mutates only what its other changes reach.
- A package left out of the setting that does change a verdict keeps an old
  verdict until the scheduled full run. The gate's own list is held by a test.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 1, which this narrows for the lock
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key, which keeps every package
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the carried kills
