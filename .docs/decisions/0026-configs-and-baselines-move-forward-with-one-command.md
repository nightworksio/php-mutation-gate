# ADR-0026: Configs and baselines move forward between versions with one command

**Status:** Accepted
**Date:** 2026-09-30

## Context

The config and the baseline are committed, and both are public API (ADR-0011
decision 7). A setting is deprecated with a warning for at least one minor
release and removed only in a major (ADR-0011 decision 7). A project on an
older major needs a way to its successor's config that does not mean reading
a changelog key by key.

What exists to build on:

- **The config has no version marker.** JSON's `$schema` names the major
  version in its URL (`…/php-mutation-gate/v1/resources/mutation-gate.schema.json`,
  ADR-0002 decision 3). The baseline has `"format": 1` (ADR-0003
  decision 3).
- **Every format reads into one form.** Each config file, in any of the four
  formats, is read through the same definition (ADR-0002 decision 1), and an
  unknown key is an error that names the nearest known key (ADR-0002
  decision 6).
- **The formats differ in what a rewrite keeps.**
  - `config:show --format=…` writes any format from the effective config,
    and writes no comments.
  - php-parser's format-preserving printer keeps a PHP file's layout and
    comments, and edits only the nodes that change.
  - `symfony/yaml` and `nette/neon` parse into plain PHP values, which hold
    no comments, so a file they write back has none.
- **The ledger needs no migration.** A ledger of another format is not read,
  and the verdict warns so, which costs a run and never a verdict (ADR-0007
  decision 3).

## Decision

1. **A file's version is known by what it holds.**
   - Every key the gate has ever renamed or removed stays known to the
     definition, with the version that changed it.
   - A file that holds one is read as that version's file. The error for it
     names the change and the command, in the form *`<old key>` became
     `<new key>` in <version>: run `mutation-gate migrate`*.
   - No file carries a version key.

   This amends ADR-0002 decision 6.

2. **A migration is data: typed steps in `Core`.**
   - The steps are `Rename(from, to)`, `Move(from, to)`, `MapValue(key, old,
     new)`, `Split` and `Remove(key, because)`, each tied to the version that
     made it.
   - They apply to the JSON form every format reads into (ADR-0002
     decision 1), before the definition reads it, so one step serves all four
     formats.
   - Each step has a fixture per format, and a test that the migrated file
     reads with no problem.

3. **The file is written back keeping what its format can keep.**
   - **PHP:** php-parser's format-preserving printer edits only the builder
     calls a step touches, and keeps every comment and the layout.
   - **JSON:** each step is a key-level text edit, since JSON holds no
     comments.
   - **YAML and NEON:** the file is written again from its migrated form,
     and the output says, before anything is written, that its comments are
     not kept.

   The PHP config is parsed, never included, so migrating it runs none of it.
   This needs `nikic/php-parser` in `require`, as ADR-0021 puts it.

4. **The baseline migrates too.** A change of the baseline's `format` is
   migrated by the same command and the same kind of step. The ledger is not
   migrated (ADR-0007 decision 3).

5. **`mutation-gate migrate [--config=<path>] [--write]` shows before it
   writes.**
   - It prints a unified diff of every file it would change, and writes only
     with `--write`, as `baseline --write` does (ADR-0003 decision 6).
   - Exit 0 when every file is current or has been written.
   - Exit 1 when a migration is pending and `--write` was not given, so CI
     can check that nothing is behind.
   - Exit 2 when the named file is not a config the gate can read.
   - It writes only the config and the baseline, and nothing else.

   The command is public API. This amends ADR-0002 decision 2 and ADR-0011
   decision 7.

6. **The machinery is tested with synthetic old keys.** Its steps, the
   format-preserving PHP edit and the command are part of the first release,
   which has no older version to migrate from, and are tested with keys made
   up for the tests.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A `version` key in every config, written by `init`** | A key every file must carry, which a hand edit forgets to bump. The keys that make a file old already say how old it is. |
| **A PHP class per version, with free code** | Flexible, untestable in general, and it grows without limit. Typed steps are data. |
| **Always writing the file again through `config:show`'s writer** | One writer, and every comment and custom line of a PHP config, the canonical format, lost. |
| **The config only** | The first change of the baseline's format would need a second tool. |
| **Writing without being asked** | A migration is a change to a committed file, and a reviewer should see its diff first. |

## Consequences

**Moving to a new major is one command,** whose diff a reviewer reads before
it is written.

**A PHP config keeps its comments and layout** through a migration. YAML and
NEON say beforehand that theirs do not.

**CI can check that a project's config is current** from the exit code.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): the one form every format reads into, and how an unknown key is reported
- [ADR-0003](0003-a-floor-only-rises.md): the baseline and `baseline --write`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): why a ledger needs no migration
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): what semver protects, and how a removal is deprecated
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): php-parser in `require`
