# ADR-0029: An optional native helper computes what the core defines, and the core checks every answer against its own

**Status:** Accepted
**Date:** 2026-10-10

## Context

A plan spends most of its own time, as opposed to running the project's
tests, computing keys. Measured on this repository's own plan, with a coverage
map handed in, on an M1 Pro shared with other work (so read as ratios):

- **coverage-entry keys** (ADR-0023 decision 1): 20.6 s of a 91 s plan. For
  each of 1,397 test files, a walk of the name graph from the file, the files
  its tests executed and what every entry reads, then a SHA-256 over every
  file reached with its digest;
- **unit proof keys** (ADR-0007): 11.7 s, hashing the ids of the tests that
  cover each covered line.

Running the project's tests is about 98% of a full run's runner time, and
must stay PHP: it is the project's own code. What can move is the plan's own
computation, which PR runs, `watch` and `pre-push` wait for.

What the PHP ecosystem does, read at source:

- **PHPStan Turbo** (PHPStan 2.2.6, 2026-07-26) is an optional C++ extension
  that replaces hot classes in place. It is gated on an exact version, falls
  back to the PHP classes, and its CI requires byte-identical output with and
  without it. It ships one `.so` per platform, PHP minor and thread mode in
  the Composer package: 20 MB in this repository's `vendor/`.
- **Mago** is a Rust PHP toolchain whose Composer package downloads a binary
  on first run without checking it. The gate already runs it as a static
  checker, as a process (ADR-0020 decision 18).
- **Composer has no per-platform packages.** A package carries every
  platform's binary, or downloads one.
- **FFI** is not loaded on setup-php's Windows images, and the official `php`
  Docker image this package's image is built from has none.

A spike measured both candidates: a Rust helper answered all 1,397 entry keys
of this repository byte for byte as the PHP does, in 0.22 s, against 19.1 s in
PHP.

## Decision

1. **The helper is optional, and the core stays the definition.**
   - The helper is `mutation-gate-turbo`, a Rust binary built from `turbo/`
     in this repository and released with the gate.
   - It answers only what the core already computes. No result exists only
     in Rust, and every change to a computation the helper answers lands in
     both in the same pull request.
   - Without it, the gate computes the same in PHP. No verdict, key or plan
     depends on whether it ran, so it is in no proof key (ADR-0007).

2. **It is asked through the `Accelerator` port.**
   - `Accelerator::answer(Request): Answer|NotAccelerated`. The core writes
     every request and reads every answer as untrusted input, in a JSON
     protocol whose number both sides carry and which changes whenever a
     request or an answer does.
   - The `Sidecar` adapter writes a request to a file under
     `.mutation-gate/turbo`, runs `mutation-gate-turbo answer <file>`
     through the `Processes` port with a limit, and reads what it prints. Large
     inputs go by path, never through the pipe.
   - `Unavailable` answers every request with why there is no helper.
   - The contract suite runs the fake, the sidecar and `Unavailable` over the
     same fixtures, and requires each to answer exactly the core's own keys,
     or nothing. It is the parity test between the helper and the PHP.
   - This amends ADR-0001 decision 2: there are twelve ports.

3. **A helper is asked only when it is exactly the one this gate expects.**
   - `MUTATION_GATE_TURBO=off` turns it off.
   - `MUTATION_GATE_TURBO_BINARY` names a binary of the operator's own, run
     unpinned, as for a local build.
   - Otherwise the gate runs the optional package's binary for its platform
     only where its SHA-256 is the one this gate's release pins. The pins live
     in the gate, not in the package, so a swapped package cannot bring its
     own.
   - Every binary must then say, in its handshake, that it is
     `mutation-gate-turbo` at exactly the version this gate asks, on its
     protocol.
   - Anything else leaves the gate in PHP, and says why.

4. **Every answer is checked, and one wrong answer refuses the helper.**
   - The core reads an answer strictly: the protocol, one key for each item
     asked, each a SHA-256.
   - Each run recomputes a sample of the answered keys in PHP: fifty, or a
     fiftieth of them where that is more, chosen by their digest beside the
     run's base, so which are checked changes with every state of the
     repository and nobody picks them.
   - On any difference the run drops the helper, computes everything in
     PHP, and warns.
   - Where the core's own order of the paths cannot be reproduced exactly
     (PHP's `sort()` on strings that mix numbers with words is not a total
     order), the helper refuses rather than guesses.

5. **The helper is distributed as an optional Composer package of static
   binaries.**
   - `nightworksio/mutation-gate-turbo` carries
     `bin/<platform>/mutation-gate-turbo` for `linux-x86_64` and `linux-arm64`
     (static musl, serving glibc and musl alike), `macos-arm64`,
     `macos-x86_64` and `windows-x86_64`. Every other platform runs PHP.
   - The release workflow builds them from the tagged commit, writes their
     SHA-256 into the gate's pin table, and attests each binary with a GitHub
     artifact attestation, as it attests the PHAR (ADR-0022 decision 12).
   - The PHAR extracts the binary beside the workspace and checks its pin
     before each run. The image carries its architecture's binary.
   - The package and its pins are wired once the spike's numbers clear the
     bar the maintainer set: 10 s per plan on this repository, or 25% of a
     large consumer's plan.

6. **The crate holds itself to the package's standards** (ADR-0011).
   - A workspace in `turbo/` with a committed lock and a pinned toolchain.
   - `unsafe` forbidden.
   - `cargo fmt`, clippy with every pedantic lint and `-D warnings`, its
     tests, and `cargo deny` over licences, advisories and sources, all in
     CI.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A PHP extension, as PHPStan Turbo** | A build per PHP minor, thread mode and platform, and a restart through `pcntl_exec` that Windows lacks. Its gain is absorbing call frames; the gate's hot paths are bulk data in and digests out, which a process answers at the same speed. |
| **FFI** | Not loaded on setup-php's Windows images or in the official `php` image, and the answer still has to be built into PHP values. |
| **A long-lived daemon** | State to invalidate on every file change, a socket to secure, and stale answers become possible, for a saving of milliseconds per call. |
| **Downloading the binary on first use** | Network at run time, and the check moves to a place a cache can poison. The pinned package needs neither. |
| **The binary in the gate's own package** | Several megabytes for every user, including those who never want it. |
| **The helper's build in the proof key** | Proofs made with it would never hit without it, although its answers are identical by construction and checked on every run. |
| **Trusting the helper without a sample** | One wrong key carries a wrong verdict. The sample costs a few PHP keys a run. |
| **Moving the PHP fixes to Rust instead of making them** | The PHP stays the definition and runs wherever the helper does not, so it must be fast on its own. |

## Consequences

**A plan computes its keys in a fraction of a second where the helper is
installed,** and in PHP everywhere else, with the same result.

**A broken or altered helper cannot change a verdict.** It is refused before
it is asked, or its answer is refused when a sampled key differs.

**The gate is now built in two languages,** and every computation the helper
answers has two implementations that the contract suite and the sample hold
together.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the ports, now twelve
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): proof keys, which the helper is not part of
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the standards the crate is held to
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): Mago, a native tool the gate already runs as a process
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the release job's attestations, the PHAR and the image
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): coverage-entry keys
