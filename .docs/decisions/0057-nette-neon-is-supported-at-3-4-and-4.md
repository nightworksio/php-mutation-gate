# ADR-0057: nette/neon is supported at 3.4 and at 4

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its supported versions, for `nette/neon`

## Context

ADR-0011's table of supported versions lists `nette/neon` at `^3.4`, the
optional package that reads and writes a `mutation-gate.neon`. `nette/neon`
4.0.0 is out, and it needs PHP 8.4 to 8.6, which the gate's own range fits.
No `conflict` keeps a project from installing it today, so a project on it
runs code that CI does not test.

Neon 4 writes a map keyed from 0 up as a list, whether or not it was decoded
as an associative array, so the test that told the two apart on neon 3 no
longer does. What still differs is an empty map: `[]` from an associative
array, `{}` from an object.

## Decision

1. **`nette/neon` is supported at `^3.4 || ^4.0`**, and `composer.json`'s
   `require-dev` says so.
2. **CI tests both ends.** The lowest dependencies run on 3.4, and every
   other job on 4.0, which the lock holds.
3. **A test pins the empty map**: written as NEON, it reads back as the same
   empty array on 3.4 and on 4.

This amends ADR-0011's row "Optional: `symfony/yaml`, `nette/neon`,
`async-aws/s3`" to `^7.4 || ^8.0`, `^3.4 || ^4.0` and `^3`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Stay on `^3.4`, with a `conflict` on 4** | A project on neon 4 could not install the gate, though nothing in it fails there. |
| **Stay on `^3.4` without a conflict** | A project on neon 4 would run what CI never tests. |

## Consequences

- A project may install neon 3.4 or 4, and both are tested.
- The lock moves `nette/neon` to 4.0.0.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): the config formats, NEON among them
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the supported versions this amends
