# ADR-0115: A lock move can reach only what loaded the package

**Status:** Accepted
**Date:** 2026-11-07
**Amends:** [0030](0030-a-lock-file-decides-by-the-packages-it-moved.md): what a lock's move reaches; [0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 1

## Context

A change to a `composer.lock` reaches every unit, unless `reach.lockPackages`
lists the packages that decide. A list needs someone to keep it, and it says
nothing about which units a package touches, so a lock-only pull request
either runs every unit or runs none. A package most tests never load still
costs a whole run.

## Decision

1. **`reach.lockMoves` says what a move reaches:** `everything`, the default;
   `named`, by `reach.lockPackages`, which a layer that lists them selects; or
   `loaded`.

2. **Under `loaded`, the coverage run records what each test loaded.** Each
   process notes, as each test finishes, the Composer packages it first loaded
   by then, through `get_included_files()` and what Composer installed. A
   process keeps what it loaded, so a package counts as loaded by the test that
   first loaded it and by every test after it in that process. The record goes
   in the coverage map.

3. **A moved package reaches the files its loading tests ran.** A test that
   started a process, which `getrusage()` of the process's children shows once
   it is waited for, counts as loading every package.

4. **Some moves still reach every unit:** a package added or removed; a move
   of a package's `autoload.files`, `extra` or `type`; a move of a key besides
   the package lists; a move of a package this repository ships; and any move
   where the map does not record every test.

5. **A `composer.json` whose package lines moved leaves them to the lock**,
   where its other keys stayed.

## Alternatives considered

- **Making `loaded` the default.** It can miss what a process no test started
  loads, so a kill it carries may no longer hold. `everything` stays the
  default, and a config chooses the risk.
- **Reaching every unit from a test that started a process.** A mutant's kill
  depends only on the tests that judge its unit, so reaching the files that test
  ran is as sound and keeps the setting useful to a suite with one such test.
- **A delta of loaded files for each test.** A class loads once in a process,
  so a later test using it would load nothing and read as not using it.

## Consequences

- A child process not yet waited for when its test ends, a worker already
  running, a package read as data rather than loaded, and a preloaded one are
  not seen, so `loaded` can miss what such a test reaches. A scheduled full run
  bounds what that can hide, as it does for `reach.lockPackages`.
- Coverage maps grow by what the record holds, only where `loaded` is set.
- Only the Pest runner records. Any other runner's map holds no record, so a
  move reaches every unit, as it does on the first run after setting the key.

## Related

- [0030](0030-a-lock-file-decides-by-the-packages-it-moved.md)
- [0056](0056-a-manifest-decides-how-the-gate-runs-only-by-the-keys-that-install-and-load.md)
- [0065](0065-a-changed-test-file-reaches-what-its-changed-tests-run.md)
