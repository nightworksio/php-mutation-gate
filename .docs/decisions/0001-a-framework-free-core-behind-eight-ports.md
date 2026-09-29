# ADR-0001: A framework-free core behind eight ports, with adapters found through Composer

**Status:** Accepted
**Date:** 2026-09-29

## Context

mutation-gate turns mutation testing into a CI gate. It does not mutate code
itself. It decides what to mutate, where to run it and whether the result may
merge, and it leaves the mutating to a tool that already does it: Pest's
`--mutate` or Infection.

It is extracted from an in-house gate. That gate holds each tree its coverage
measures to the mutation floor the nearest manifest declares: 100 for all but
four, which declare 0 with a reason. It spreads the work over a CI matrix, and
it skips work it has already proved. It is one 1,276-line script that knows its
repository's layout by heart: the module directories, the test support that
lists the measured trees, one GitHub workflow and one runner, Pest, driven with
patches to Pest's vendor code. A generic core, the seed, asks the repository
through five seams instead of naming it: `TheLayout`, `TheRepository`,
`ThePest`, `WhatALineCosts` and `WhatAFileCosts`.

A public package meets every combination the in-house gate never had to handle.
It has to support Pest or Infection, and GitHub, GitLab, Buildkite or CircleCI.
Its config may be a PHP file or JSON. Proofs may live in a GitHub cache or an S3
bucket, and a repository may be a Laravel app or a monorepo. If each combination
lived in the code that decides, that code would become the part nobody can test.

The approved shape is ports and adapters with a framework-free core. The ports
are Runner, TreeSource, CostModel, ProofStore, CiPlan, Reporter, ChangeSource and
ConfigLoader. Third-party adapters are found through Composer's `extra`, and
arch tests keep the core isolated. This ADR fixes where the lines run.

## Decision

1. **Seven layers, each in its own namespace under `NightWorksIO\MutationGate`,
   in this order: `Core`, `Attribute`, `Port`, `Config`, `Extension`,
   `Adapter\<Name>`, `Cli`.** A layer names only itself and the layers before
   it.
   - **`Core`** decides. It holds the values: trees, units, mutants, reach,
     plans, shards, proofs and verdicts. It also holds the steps that turn one
     into the next: *plan*, *run a shard* and *judge*. It performs no I/O. It
     does not read a file, start a process, open a socket, read the clock or
     draw a random number. It is handed everything it reads as a value, and
     everything it produces is a value.
   - **`Attribute`** holds `#[Holds]` (ADR-0005). It names nothing, and nothing
     in `src` names it, because the gate reads it from tokens. It is public API.
   - **`Port`** holds the eight interfaces through which the gate asks the
     outside world, and nothing else. They are public API.
   - **`Config`** is the PHP builder of ADR-0002. It is public API.
   - **`Extension`** holds the `Extension` interface, the `Extensions` registry
     and `Configurable` (below and ADR-0002). It is public API.
   - **`Adapter\<Name>`** implements a port against one outside thing: Pest,
     Infection, git, the GitHub API, a directory, S3, a YAML parser. An adapter
     may use the layers before it and the one library it adapts, and no other
     adapter. Adapters that speak HTTP (the GitHub API, S3) use
     `symfony/http-client`, which `async-aws/s3` is built on too, and
     `symfony/process` starts every process.
   - **`Cli`** is the composition root. It is the only place adapters are
     constructed and wired, and it uses `symfony/console` for the command line.
     Its flows ask the ports in order and hand their answers to the core.

2. **The eight ports, and what each one answers.** Every method returns a value
   or an outcome. None throws across the port (see decision 6).

   | Port | Answers | First adapters |
   |------|---------|----------------|
   | `Runner` | Its identity and versions, the suite's groups, a per-test coverage map, which tests can judge a file, and every mutant's result for a run over some files judged by some tests | Pest, Infection (ADR-0004) |
   | `TreeSource` | Which trees exist, the floor each one declares, and which package each belongs to | `phpunit` (`phpunit.xml` `<source>`) and `composer` (autoload paths), each reading declared floors from manifests (ADR-0002, ADR-0005) |
   | `CostModel` | What a unit costs a runner in seconds, and what a finished shard teaches it | Learned timings, lines × seconds per line (ADR-0006) |
   | `ProofStore` | The ledgers of proved results this run may read, and where to write the new one | Directory, S3-compatible (ADR-0007) |
   | `CiPlan` | A plan in a CI's own format; which shard this job is; and the run's ref, whether it is a pull request, and the default branch | GitHub, GitLab, Buildkite, CircleCI, JSON (ADR-0006) |
   | `Reporter` | Writing a verdict for one audience | Console, JSON, JUnit, SARIF, HTML, GitHub annotations and step summary, PR comment, badge and trend (ADR-0009) |
   | `ChangeSource` | The repository as version control sees it: what changed since a base and on which lines, every file with a digest of its content and the time it last changed, and a file as it was at the base | git, with GitHub as a source for the base (ADR-0005) |
   | `ConfigLoader` | One config file read into the untyped tree that ADR-0002 validates | PHP, JSON, YAML, NEON (ADR-0002) |

   Time is read through PSR-20's `Psr\Clock\ClockInterface`, a standard
   interface rather than a ninth port. `Psr\Clock` is the only package outside
   PHP that `Core`, `Attribute`, `Port`, `Config` and `Extension` may name.

3. **The seed's seams map onto the ports, and do not survive as names.**

   | Seed | Here |
   |------|------|
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
   plugin and needs no `allow-plugins` entry.
   - Two extensions that register the same name for the same port stop the run
     with exit code 2, and the message names both packages.
   - A config may also name extension classes directly, in `extensions`
     (ADR-0002).
   - `--no-extensions`, accepted by every command, loads the first-party
     extension alone: neither Composer discovery nor the config's `extensions`.
     It is for telling a fault in the gate from a fault in an extension.

5. **The arch tests that hold the boundary.** Each of the following is a Pest
   arch test or a PHPStan rule, listed in `ARCHITECTURE.md` beside what enforces
   it (ADR-0011):
   - `Core`, `Attribute`, `Port`, `Config` and `Extension` name nothing outside
     the package but PHP and `Psr\Clock`.
   - A layer names only itself and the layers before it.
   - The filesystem, the standard streams, processes, the network, the
     environment, the system clock and waiting belong to adapters and `Cli`.
     Nothing in `src` draws a random number. The spaze disallowed-calls lists
     enforce these, scoped by path.
   - `Port` holds interfaces only, and no port method answers `void`.
   - The API surface is every class in `Attribute`, `Port`, `Config` and
     `Extension`, and every `Core` type their public signatures reach. On it, no
     signature takes or returns `null`, an array or `mixed`, and a bare string,
     int or float is taken only by a named constructor.
   - An `Adapter\<Name>` uses no other `Adapter\*`.
   - Only `Cli` constructs adapters.
   - Every port has a hand-written fake in `tests/Fakes`, and one contract suite
     per port runs against that fake and against every adapter of it.
   - Every class is `final`, and `readonly` where it can be.

6. **Outcomes, not exceptions, cross a port.** A runner whose opening test run
   fails returns *the suite failed before anything was mutated*, with the
   output. It does not throw. Git that cannot say what changed returns *cannot
   tell*, which the core reads as *reach everything* (ADR-0005). Every *cannot*
   carries the sentence the user will read. Exceptions stay inside an adapter,
   and a PHPStan rule refuses a catch of `Throwable` or `Exception` that does
   not rethrow.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Keep one script, made configurable** | This is the in-house gate's shape. Every new runner, CI or store becomes a branch inside the code that decides, and the planning, reach and proof logic stays untestable without a real repository, a real Pest and a real CI. |
| **Pest and Infection adapters as separate packages** (`mutation-gate-pest`, `mutation-gate-infection`) | The approved scope ships both in v1, and a user would install two packages to use one. Splitting adds a version matrix between core and adapters before anyone needs it. They ship in the package, registered through the same discovery a third-party adapter uses, so splitting them changes no interface. |
| **A Composer plugin that writes a generated registry at install time** (the approach of `phpstan/extension-installer`) | Needs an `allow-plugins` entry in every consuming project, and the registry goes stale when the plugin is not allowed to run. Reading `installed.json` at startup costs one JSON decode. |
| **Discovery by scanning for classes that implement `Extension`** | Loads every class in `vendor` to find a handful, and an extension is enabled merely by being autoloadable, where naming it in `composer.json` is a declaration. |
| **A ninth port for the clock** | Time is needed for ignore expiry and the time budget, and PSR-20 already is that interface. A port of our own would add a type every adapter author has to learn. |
| **Deptrac for the layer rules** | Pest arch tests and PHPStan already run in the suite, and the Guards suite proves each of their rules refuses a violation (ADR-0011). A third tool would hold the same rules in a second place. |

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
