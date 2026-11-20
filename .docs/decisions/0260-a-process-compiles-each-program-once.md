# ADR-0260: A process compiles each program once

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): how the equivalence proof compiles

## Context

ADR-0013 proves a survivor equivalent when opcache compiles it to the
original's opcodes. Each proof writes the original and the mutant to disk and
compiles them in `php -n` children, many programs to a child.

A process often proves the same programs again. `watch` and the daemon judge
the survivors of every unit a round holds, the carried ones included, so each
round compiles every unchanged survivor and its original again. In this
package's own suite, `WatchCommandTest` spends about 5 of its 16 seconds in
those children. The same process also counts the machine's cores on every
composition, and each count asks the system: 0.7 s of the same test.

Opcache compiles a file without reading any other one. It sets
`ZEND_COMPILE_IGNORE_OTHER_FILES` and binds classes late, which is what lets it
cache a file by itself. So a program compiled again, by the same PHP with the
same settings, compiles to the same opcodes. `Opcodes` already takes out the
program's own path, which is all that tells two copies apart.

## Decision

1. **A process keeps the opcodes of the programs it compiled.** Each is kept
   by everything its compile reads: the PHP binary, the child's settings and
   the program's text. A program kept is not written or compiled again; the
   rest are compiled as before.
2. **Only a program that compiled is kept.** A failure, a child that ran out
   of time, and a PHP without opcache are never kept, so each is tried again
   as before.
3. **`staticCheck.opcache.kept` says how many programs a process keeps.** The
   most recently used are kept. The default is the number of dumps that fit in
   6 MiB, a footprint a long-running daemon can carry, at the size of a dump at
   the 90th percentile of this package's own 2,800 source files. That size is
   15,193 bytes, rounded up to 16 KiB, which gives 384 programs. `0` keeps
   none, and every program is compiled each time, as before
   ([ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md)). The
   settings `batch`, `limit` and `kept` are read as one value,
   `OpcacheCompile`, which the prover is built from.
4. **A process counts its cores once.** A machine's cores do not change while
   the gate runs.

A verdict never changes: a kept program's opcodes are those a fresh compile
gives.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Turning `equivalence.static` off in the tests that do not assert it** | Only the tests get faster; every `watch` round still compiles unchanged survivors again. |
| **Handing the prover and the core count to the composition, so tests pass fakes** | Only the tests get faster, and the prover needs a port of its own to fake. |
| **Keeping the opcodes on disk across processes** | A file cache would outlive the PHP binary and opcache build it was made with, unless its key held them. In-process covers `watch` and the daemon, where programs repeat. |
| **Capping by bytes instead of programs** | A program's dump is small and fairly even in size; a count is easier to reason about, like `staticCheck.opcache.batch`. |
