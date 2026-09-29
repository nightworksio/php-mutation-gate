# ADR-0001: A framework-free core behind eight ports, with adapters found through Composer

**Status:** Accepted
**Date:** 2026-09-29

## Context

mutation-gate turns mutation testing into a CI gate. It does not mutate code
itself. It decides what to mutate, where to run it and whether the result may
merge, and it leaves the mutating to a tool that already does it: Pest's
`--mutate` or Infection.

It is extracted from the lemonfiber companion's gate. That gate holds each tree
its coverage measures to the mutation floor the nearest manifest declares (100
for all but four, which declare 0 with a reason). It spreads the work over a CI
matrix, and it skips work it has already proved. It is also one 1,276-line script,
`scripts/mutation.php`, that knows the companion's layout by heart: `app-modules/*`,
`bridge/tests`, `tests/Support/MeasuredTree`, one GitHub workflow called `ci.yml`
and one runner, Pest, driven with patches to Pest's vendor code. A generic core
has since been started on the companion's `wip/mutation-gate-seed` branch
(namespace `Dx\Mutation`). It asks the repository through five seams instead of
naming it: `TheLayout`, `TheRepository`, `ThePest`, `WhatALineCosts` and
`WhatAFileCosts`.

A public package meets every combination the companion never had to handle. It
has to support Pest or Infection, and GitHub, GitLab, Buildkite or CircleCI. Its
config may be a PHP file or JSON. Proofs may live in a GitHub cache or an S3
bucket, and a repository may be a Laravel app or a monorepo. If each combination
lived in the code that decides, that code would become the part nobody can test.

The maintainer has approved the shape already: ports and adapters with a
framework-free core. The ports are Runner, TreeSource, CostModel, ProofStore,
CiPlan, Reporter, ChangeSource and ConfigLoader. Third-party adapters are found
through Composer's `extra`, and arch tests keep the core isolated. This ADR fixes
where the lines run.

## Decision

1. **Four layers, each in its own namespace under `NightWorksIO\MutationGate`.**
   - **`Core`** decides. It holds the values: trees, units, mutants, reach,
     plans, shards, proofs and verdicts. It also holds the steps that turn one
     into the next: *plan*, *run a shard* and *judge*. It performs no I/O. It
     does not read a file, start a process, open a socket, read the clock or
     draw a random number. Everything it learns comes through a port, and
     everything it produces is a value.
   - **`Port`** holds the eight interfaces the core asks, and nothing else. They
     are public API.
   - **`Adapter\<Name>`** implements a port against one outside thing: Pest,
     Infection, git, the GitHub API, a directory, S3, a YAML parser. An adapter
     may use `Core`, `Port` and the one library it adapts, and no other adapter.
     Adapters that speak HTTP (the GitHub API, S3) use `symfony/http-client`,
     which `async-aws/s3` is built on too, and `symfony/process` starts every
     process.
   - **`Cli`** is the composition root. It is the only place adapters are
     constructed and wired, and it uses `symfony/console` for the command line.
     `Config` (the builder of ADR-0002) and `Extension` (below) are public API
     beside `Port`, and they depend on `Core` only.

2. **The eight ports, and what each one answers.** Every method returns a value
   or an outcome. None throws across the port (see decision 6).

   | Port | Answers | First adapters |
   |------|---------|----------------|
   | `Runner` | Its identity and versions, the suite's groups, a per-test coverage map, which tests can judge a file, and every mutant's result for a run over some files judged by some tests | Pest, Infection (ADR-0004) |
   | `TreeSource` | Which trees exist, the floor each one declares, and which package each belongs to | `phpunit.xml` `<source>`, Composer autoload, monorepo manifests (ADR-0002, ADR-0005) |
   | `CostModel` | What a unit costs a runner in seconds, and what a finished shard teaches it | Learned timings, lines × seconds per line (ADR-0006) |
   | `ProofStore` | The ledger of proved results this run may read, and where to write the new one | Directory, S3-compatible (ADR-0007) |
   | `CiPlan` | A plan in a CI's own format, and which shard this job is | GitHub, GitLab, Buildkite, CircleCI, JSON (ADR-0006) |
   | `Reporter` | Writing a verdict for one audience | Console, JSON, JUnit, SARIF, GitHub annotations, HTML, PR comment, badge (ADR-0009) |
   | `ChangeSource` | The repository as version control sees it: what changed since a base and on which lines, every file with a digest of its content, and a file as it was at the base | git, with GitHub as a source for the base (ADR-0005) |
   | `ConfigLoader` | One config file read into the untyped tree that ADR-0002 validates | PHP, JSON, YAML, NEON (ADR-0002) |

   The core reads time through PSR-20's `Psr\Clock\ClockInterface`, a standard
   interface rather than a ninth port. It is the only package outside PHP that
   the core may name.

3. **The seed's seams map onto the ports, and do not survive as names.**

   | Seed (`Dx\Mutation`) | Here |
   |----------------------|------|
   | `ThePest` | `Runner`, with Pest as one adapter |
   | `TheRepository` | `ChangeSource` |
   | `TheLayout` | Config (ADR-0002) and framework presets (ADR-0008). A layout is data a repository declares, not code it implements. |
   | `WhatALineCosts`, `WhatAFileCosts`, `SecondsPerLine`, `SecondsMeasured` | `CostModel` |
   | `ProvenVerdicts` | The ledger value in `Core`, stored through `ProofStore` |
   | `WhatAVerdictReads` and its readers | The content key in `Core` (ADR-0007) |
   | `WhatIsMutated`, `TheHoldingGroups`, `WhatAChangeReaches`, `TheCut` | Planning in `Core` |
   | `TheGateCannotRun` (an exception) | An outcome, *cannot judge*, with exit code 2 |

4. **Adapters are found through Composer's `extra`, and first-party adapters are
   found the same way.** A package offers adapters by naming one or more
   extension classes in its `composer.json`:

   ```json
   {
       "extra": {
           "mutation-gate": {
               "extensions": ["Acme\\GateSlack\\SlackExtension"]
           }
       }
   }
   ```

   At startup the CLI reads `vendor/composer/installed.json` and the root
   `composer.json`, and it constructs each named class. Each class implements
   `NightWorksIO\MutationGate\Extension\Extension`:

   ```php
   interface Extension
   {
       public function extend(Extensions $extensions): Extensions;
   }
   ```

   `Extensions` is an immutable registry. An extension returns it with adapters
   added under a name: `withRunner('pest', …)`, `withReporter('sarif', …)`,
   `withProofStore('s3', …)`, `withCiPlan('gitlab', …)`,
   `withConfigLoader('yaml', …)`, `withPreset('laravel', …)` and so on for every
   port. The package's own adapters register through a first-party extension
   named in the package's own `extra`. The discovery path is therefore the path
   every built-in takes, and it cannot rot unnoticed. Discovery runs no Composer
   plugin and needs no `allow-plugins` entry. Two extensions that register the
   same name for the same port stop the run with exit code 2, and the message
   names both packages. `--no-extensions` turns discovery off for everything
   but the first-party extension. A config may also name extension classes
   directly (ADR-0002).

5. **The arch tests that hold the boundary.** Each of the following is a Pest
   arch test or a PHPStan rule, listed in `ARCHITECTURE.md` beside the test that
   enforces it (ADR-0011):
   - `Core` uses nothing outside PHP, itself and `Psr\Clock`.
   - `Core` calls no I/O function: filesystem, process, network, clock,
     randomness, output or `getenv`. The spaze disallowed-calls lists allow them
     only in adapters, as the companion's lists do for its capabilities.
   - `Port` holds interfaces only. Every signature uses types from `Core`,
     never an array, `mixed` or a bare primitive outside a named constructor.
   - An `Adapter\<Name>` uses no other `Adapter\*`.
   - Only `Cli` constructs adapters.
   - Every port has a hand-written fake in `tests/Fakes`, and one contract suite
     per port runs against that fake and against every adapter of it.
   - Every class is `final` and `readonly` where it can be.

6. **Outcomes, not exceptions, cross a port.** A runner whose opening test run
   fails returns *the suite failed before anything was mutated*, with the
   output. It does not throw. Git that cannot say what changed returns *cannot
   tell*, which the core reads as *reach everything* (ADR-0005). Every *cannot*
   carries the sentence the user will read. Exceptions stay inside an adapter,
   and a PHPStan rule refuses a catch of `Throwable` or `Exception` that does
   not rethrow (the companion's `NoBroadCatchRule`).

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Keep one script, made configurable** | This is the companion's shape. Every new runner, CI or store becomes a branch inside the code that decides, and the planning, reach and proof logic stays untestable without a real repository, a real Pest and a real CI. |
| **Pest and Infection adapters as separate packages** (`mutation-gate-pest`, `mutation-gate-infection`) | The approved scope ships both in v1, and a user would install two packages to use one. Splitting adds a version matrix between core and adapters before anyone needs it. They ship in the package, registered through the same discovery a third-party adapter uses, so splitting them later changes no interface. |
| **A Composer plugin that writes a generated registry at install time** (the approach of `phpstan/extension-installer`) | Needs an `allow-plugins` entry in every consuming project, and the registry goes stale when the plugin is not allowed to run. Reading `installed.json` at startup costs one JSON decode. |
| **Discovery by scanning for classes that implement `Extension`** | Loads every class in `vendor` to find a handful, and an extension is enabled merely by being autoloadable, where naming it in `composer.json` is a declaration. |
| **A ninth port for the clock** | Time is needed for ignore expiry and the time budget, and PSR-20 already is that interface. A port of our own would add a type every adapter author has to learn. |
| **Deptrac for the layer rules** | The companion uses Pest arch tests plus PHPStan, and it has no Deptrac. The same tools keep the package's rules in the suite that already runs, with the Guards pattern proving each one refuses a violation (ADR-0011). |

## Consequences

**The decisions are testable without a repository.** Planning, reach, the
content key, sharding and judging run over fakes in milliseconds. Every real
adapter is proved against the same contract its fake is.

**A new CI, runner or store is a new adapter and nothing else.** Nothing in
`Core` names GitHub, Pest or S3, and an arch test fails the day something does.

**The first-party adapters carry no privilege.** A third-party runner is
registered, configured and reported exactly like Pest.

**Extension classes are public API** (ADR-0011): the `Extension` interface,
the `Extensions` registry, the ports and the `Core` value types they use. Moving
any of them is a major release.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): how a config names an adapter or an extension
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the Runner port in detail
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the content key the core computes
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the toolchain that enforces these rules
