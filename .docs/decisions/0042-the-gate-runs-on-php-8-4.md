# ADR-0042: The gate runs on PHP 8.4 and later, and CI runs it on 8.4 and 8.5

**Status:** Accepted
**Date:** 2026-10-31

## Context

What holds the gate to PHP 8.5, read at source:

- **The requirement.** `composer.json` requires `php: ^8.5`, its
  `config.platform.php` is 8.5.12, and ADR-0011 supports 8.5 and later 8.x.
  The plugins require `^8.5` too.
- **PHP 8.4 is supported upstream.** It has security support until
  31 December 2028, and a project on it cannot install the gate.
- **What of 8.5 the code uses.**
  - Clone-with, `clone($object, ['property' => $value])`, in 247 places: it
    is how each immutable value returns a copy with a field changed.
  - `array_first` and `array_last`, which `symfony/polyfill-php85` provides
    on 8.4. `symfony/console` 8 and `infection/infection` already require it.
  - Nothing else: PHPStan, set to PHP 8.4, reports clone-with alone.
- **The lowest 8.4 the lock can name.** `roave/security-advisories`
  conflicts with PHP 8.4 below 8.4.27.
- **What checks the version.** PHPStan and Rector's PHP sets read the
  lowest PHP from `composer.json`'s `require.php`.
- **CI.** Every job runs PHP 8.5, and the `lowest` job runs the suite
  against the oldest release of every dependency.

## Decision

This amends ADR-0011's items 3 and 10 and ADR-0015's item 16.

1. **The gate runs on PHP 8.4 and later 8.x.**
   - `composer.json` and each plugin's require `php: ^8.4`, and
     `config.platform.php` is 8.4.27, the lowest 8.4 the lock can name.
   - `composer.json` requires `symfony/polyfill-php85` for `array_first`
     and `array_last`.

2. **An immutable value returns a copy through its constructor.** A
   method that changes a field returns `new self(...)` with every field, the
   changed one in its place. A class whose constructor took less than every
   field takes every field in a private constructor, and a static `of`
   takes what the public constructor took.
   - `Extensions` is built by `Extensions::of(Origin)`, and an empty
     `Entries` by `Entries::none(ExtensionPoint)`.

3. **Syntax PHP 8.4 lacks is refused before a run.** PHPStan reads PHP 8.4
   from `require.php` and fails on clone-with, and Rector's PHP sets offer no
   rule past 8.4.

4. **CI runs the suite on PHP 8.4 and 8.5.** The `lowest` job runs PHP 8.4,
   the lowest `require.php` admits, against the oldest release of every
   dependency. Every other job runs PHP 8.5 against the lock.

5. **A CI definition the gate writes sets up PHP 8.4 at least**, where the
   project asks for nothing higher (ADR-0015).

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **PHP 8.5 alone, as ADR-0011 has it** | Every project on 8.4, which upstream supports for two more years, cannot install the gate. |
| **A helper that copies an object by reflection, with an array of changes** | PHPStan cannot check the changed fields' types, which it checks on `new self(...)`. |
| **Properties left writable inside the class, `private(set)`, and changed on a clone** | Each value would lose `readonly`, which holds that nothing changes it once built. |
| **The whole CI on both versions** | Twice the jobs for what the `lowest` job already runs on 8.4. |
| **A `tests` job matrix on 8.4 and 8.5** | Coverage is measured once, and the `lowest` job already runs the suite on 8.4. |

## Consequences

**A project on PHP 8.4 installs the gate.**

**A copy names every field,** so a value with many fields has long
methods that change one. PHPStan checks each field's type.

**A change that uses syntax PHP 8.4 lacks fails PHPStan,** and one that
breaks on 8.4 at run time fails the `lowest` job.

## Related

- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the supported versions and the copied `config`, which this amends
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the PHP version a written CI definition sets up, which this amends
