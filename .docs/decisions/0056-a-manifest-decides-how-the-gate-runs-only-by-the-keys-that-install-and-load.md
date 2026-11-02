# ADR-0056: A manifest decides how the gate runs only by the keys that install and load

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0030](0030-a-lock-file-decides-by-the-packages-it-moved.md): its rule, extended from the lock to the manifest

## Context

ADR-0005's rule 1 makes each package's `composer.json` a file that decides
how the gate runs. ADR-0030 narrows the lock to the packages it moved, and
leaves the manifest whole. So every change to a manifest reaches every unit.

Of the 45 pull requests this repository merged from #471 to #517, #500
changed only the manifest's `support` links and two `scripts-descriptions`.
Its verdict says "`composer.json` decides how the gate runs, so every unit
is reached", and it ran all 2,163 units on 20 shards, 383 shard-minutes.
Pull request #491 changed the `description` and the `keywords`, and the
conflict on `pestphp/pest-plugin-mutate`. Only the conflict moves what a
mutant runs against.

## Decision

1. **A change to a package's manifest in its place decides only where it
   moved what installs or loads the package.** The manifest is read at the
   base and now. The change decides where:
   - a package's line under `require`, `require-dev`, `conflict`, `replace`
     or `provide` was added, removed or changed, and the package decides as
     ADR-0030 has it decide in a lock: it matches `reach.lockPackages`, every
     package does where the setting is not set, and the packages this
     repository ships always do;
   - any other key moved, besides those that only describe the package or
     name its commands: `abandoned`, `archive`, `authors`, `description`,
     `funding`, `homepage`, `keywords`, `license`, `non-feature-branches`,
     `readme`, `scripts`, `scripts-aliases`, `scripts-descriptions`,
     `suggest`, `support` and `time`. So `autoload`, `config`, `extra`,
     `minimum-stability`, `repositories` and any key Composer adds later
     decide;
   - either version cannot be read as a JSON object.

   Otherwise it decides alike, as a lock that moved no deciding package
   does, and reaches nothing by itself. A manifest that is added, deleted or
   renamed decides as before.
2. **A carried kill follows the same rule**, as ADR-0030's rule 3 has it for
   the lock.
3. **The proof key does not change.** It still holds the manifest's digest
   (ADR-0007), so the scheduled full run re-proves every unit after any
   change to it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **The manifest decides whole (ADR-0030 as it stood)** | A change to a link or a script's description costs a full run. |
| **List the keys that decide, and leave every other out** | A key Composer adds later, or a plugin reads from `extra`, would decide nothing until someone listed it. The keys that only describe are known and stable. |
| **`scripts` decide** | The gate starts each runner itself and never runs a Composer script, so a script changes no verdict. |

## Consequences

- A change to a manifest's description, links, authors or scripts reaches
  nothing, and the run mutates only what its other changes reach. #500's
  diff reaches nothing.
- A change to a package line decides as the same move in the lock would.
  #491's conflict on `pestphp/pest-plugin-mutate` still reaches every unit.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the files that decide how the gate runs
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proof key
- [ADR-0030](0030-a-lock-file-decides-by-the-packages-it-moved.md): the lock's rule, which this extends
