# ADR-0074: Paths set mutators, timeouts and the static check by glob

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a unit's key reads; [0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change to the config file reaches

## Context

Every mutator, timeout and static-check setting applies to the whole
project. A project often holds code that needs different ones: generated
code where a mutator only makes noise, a legacy directory whose slow tests
need a longer timeout, or a directory the static analyser cannot read.
Today the only options are to give the whole project the looser setting or
to leave the code out of every tree.

Trees (ADR-0003) already name directories, and a file belongs to the
innermost tree that holds it. A tree is also a unit of scoring and
reporting, so declaring a tree to change a timeout would also change what
is scored.

## Decision

1. **`paths` lists entries, each a glob and what it sets for the files it
   matches.**

   ```json
   {
       "paths": [
           { "glob": "src/Legacy/**", "timeouts": { "seconds": 60, "most": 600 } },
           { "glob": "src/Generated/**", "mutators": { "except": ["default/Plus"] }, "staticCheck": { "tool": "none" } }
       ]
   }
   ```

   - An entry takes `glob` (required), `mutators.sets`, `mutators.except`,
     `timeouts.seconds`, `timeouts.most`, `staticCheck.tool` (`none`
     included) and `staticCheck.before`. Every other setting stays global,
     and any other key in an entry is refused.
   - The glob is matched as `trees[].exclude` matches: from the config
     file's directory, the whole path, with `*`, `?` and `**`.
   - Entries are a list, so a later layer's follow an earlier one's, and an
     entry equal to an earlier one is dropped (ADR-0002). A context block
     (ADR-0071) may hold `paths`, and its entries follow the layer's.
   - The PHP builder writes an entry with
     `PathRule::matching('src/Legacy/**', Gate::configure()->with(...))`.

2. **Every matching entry is laid over the settings, least specific first.**
   An entry is more specific than another where its glob's literal prefix,
   the characters before its first wildcard, is longer. Entries of equal
   specificity are laid in the order they are written. Each is laid setting
   by setting, as a layer is, so the innermost entry wins only for what it
   sets: `mutators` lists grow, and a timeout replaces the one beneath it.
   A file no entry matches takes the settings as they are.

3. **Each entry is judged as the config's own settings are.**
   - An entry whose timeouts, laid over the settings, leave `timeouts.most`
     under `timeouts.seconds` is exit code 2 at its path, such as
     `paths[1].timeouts.most`.
   - An entry's mutators, laid over the config's, are judged as the
     config's own are (ADR-0021), and a problem is reported at the entry's
     path, such as `paths[0].mutators.except`. So a set nothing registers,
     or a mutator no set holds, cannot be judged. Under Pest or Infection,
     which make their own mutants, a mutator only the `default` set holds is
     refused, as the entry could not turn it off. Per-path mutators reach
     only the gate's own mutator sets, never a native runner's own.

4. **A shard runs each group of its units with that group's settings.**
   `runner.pathRules` says how. Its one value, and its default, is
   `partitioned`. A shard groups its units by what the entries matching
   each set. The units no entry matches run first, exactly as they would
   without `paths`. Each other group runs after them as a shard of its own,
   with its settings and with the runner, the analyser and the mutators
   those settings build. Every other port, and what `--security` or
   `--suite` narrows to, stays the run's own. The outcomes are joined. Once
   a group makes the run certain to fail (ADR-0008), the groups after it are
   left unjudged. The plan and the cut do not change. The cost is one runner
   built, and booted, per extra group in a shard, and only where `paths` is
   used. `runner.pathRules` is in the proof key.

5. **A unit's key holds what its entries set that affects results.** The
   entries matching a unit are serialised canonically, keeping only what
   affects results (ADR-0007), and the key reads them after what its tests
   and sources read. A unit whose entries set nothing that affects results,
   including one no entry matches, reads nothing more, so its key is the
   key it would have without `paths`. The config's global part of every key
   holds no `paths`, so an entry keys apart only the units it matches.

6. **A change that moves only `paths` reaches only the files its entries
   match.** Each entry's settings affect only the units it matches (a fourth
   effect beside ADR-0007's three), so they are left out of what a change
   to the config file is compared by (ADR-0005). Where nothing else that
   decides moved, the change reaches the units whose files match the glob
   of each entry added, removed, changed or moved among the others, as it
   stood at the base or stands now, and the plan names those globs as the
   reason.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Rules on `trees` entries** | It reuses tree membership directly, but a tree is also a scoring and reporting unit, so a rule would make a tree, and a tree path is a directory, never a glob. |
| **Only the innermost match applies** | A broad entry such as `src/**` could not set a timeout that a narrower one keeps while changing the mutators. Laying matches setting by setting does what both say. |
| **List order alone, later winning** (as ESLint overrides do) | Which entry wins would depend on where it is written, not on what it matches. |
| **Every rule in the global part of the key** | Simpler, but any edit to one entry would re-run every unit. |
| **`perFile`: one boot, the request carries each file's rules, and every runner adapter applies them** | Fewest boots, but every adapter (Pest, Infection, PHPUnit, Codeception, PhpSpec, Testo) has to apply per-file bounds and mutators. It is planned as a further value of `runner.pathRules`, and refused until it is built. |
| **`hybrid`: mutants made with every mutator, those a path disables dropped before running, the timeout carried per mutant** | Fewer boots than partitioning, but limits are computed inside the adapters, so timeouts still need adapter changes. It is planned as a further value of `runner.pathRules`, and refused until it is built. |

## Consequences

**Code that needs other settings gets them without leaving its tree.** It
is scored, reported and keyed like the rest.

**An edit to one entry re-runs only what it matches.** Proofs of every other
unit carry.

**A project that uses `paths` pays a runner boot per group.** A shard whose
units no entry matches, and every project without `paths`, runs exactly as
before.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): layers, and lists that grow
- [ADR-0003](0003-a-floor-only-rises.md): trees, which a file belongs to innermost first
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a config change reaches
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proof key and what each setting can change
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): timeouts, and a run made certain to fail
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the static check
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): mutator sets, and what a runner that makes its own mutants takes
- [ADR-0071](0071-a-config-holds-a-block-for-each-context-a-run-is-in.md): context blocks
