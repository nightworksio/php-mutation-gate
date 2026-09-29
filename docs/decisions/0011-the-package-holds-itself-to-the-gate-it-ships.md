# ADR-0011: The package holds itself to the gate it ships, and to the companion's standards

**Status:** Proposed
**Date:** 2026-09-29

## Context

A mutation gate that is not mutation-tested asks users for a rigour it does not
show. The maintainer's decisions for this package are:
- it gates itself at a 100% floor;
- it runs PHPStan at max and keeps its core isolated with arch tests;
- it ships a composite GitHub Action (`action.yml`) in the same repository;
- it has no release at all until all 20 accepted features are in.

The maintainer also asked for the lemonfiber companion's standards of code
quality, testing, tooling and architecture, reusing whatever the companion has.
The companion's toolchain was inventoried at `origin/main` (8ec163a4) for this
ADR. Its main parts are:
- `composer.json` scripts and dev dependencies;
- `phpstan.neon` with 18 rules of its own;
- `rector.php`, `pint.json` and `composer-dependency-analyser.php`;
- 59 arch test files;
- the `Guards` suite, which plants a violation of every rule and requires it to
  be refused;
- `ARCHITECTURE.md` with each rule beside the test that enforces it;
- the `Floors` suite, SonarCloud, and the `lemonfiber/spec` reusable workflows
  for hygiene, security, DCO, commitlint and attribution.

Much of that is generic PHP discipline and carries over as it is. Some of it is
about Laravel, NativePHP or the companion's modules, and does not.

## Decision

1. **The package gates itself with itself, at 100.**
   - The config declares one tree, `src`, at floor 100 with new code at 100.
     Uncovered mutants count (`uncovered: count`), so every line must be both
     covered and mutation-tested.
   - The declared floor is 100, so the baseline cannot hold it lower.
   - The runner is Pest.
   - The package's CI runs the gate through its own reusable workflow and
     action from the same commit (`uses: ./`, decision 8). Every change to the
     gate, the action or the workflow is judged by the version it introduces.
   - The verdict job is a required check.
   - A weekly scheduled full run is part of it (ADR-0005).

2. **Static analysis is the companion's, less Laravel.** `phpstan.neon` runs at
   `level: max` with:
   - `phpstan-strict-rules` and `phpstan-deprecation-rules`;
   - `ergebnis/phpstan-rules` with `allRules: true`, with the companion's
     disabled list (named arguments and nullable defaults allowed);
   - `shipmonk/phpstan-rules`;
   - `tomasvotruba/type-coverage` at 100 for declare, return, param, property and
     constant;
   - `tomasvotruba/cognitive-complexity` at 30 per class and 8 per function;
   - `spaze/phpstan-disallowed-calls` with its four bundled lists;
   - `checkUninitializedProperties`, `checkImplicitMixed`,
     `checkBenevolentUnionTypes`, `checkTooWideReturnTypesInProtectedAndPublicMethods`
     and `reportAnyTypeWideningInVarTag`.

   There is no baseline and no `ignoreErrors`, ever, as in the companion's own
   ADR (`0003-no-baseline-ever`).

3. **The companion's toolchain, piece by piece.** Each piece is *copied* (taken
   as it is), *adapted* (taken and changed for a single library), or *not
   applicable*.

   | Piece | In the companion | Here | Why |
   |-------|------------------|------|-----|
   | `composer ci` script chain | validate `--strict`, `validate:modules`, normalize, `normalize:modules`, audit, lint, analyse, refactor, deps, `test:guards`, `test:report`, `test:floors`, `test:mutation` | **Adapted** | The same chain less the two `:modules` steps, since there are no modules. `test:mutation` runs `bin/mutation-gate`. |
   | Other scripts | `analyse`, `lint`/`lint:fix`, `refactor`/`refactor:fix`, `deps`, `test`, `test:coverage`, `test:report`, `test:floors`, `test:guards`, with `scripts-descriptions` | **Copied** | Same names, same meaning, so the same muscle memory works in both repositories |
   | `post-install-cmd` / `post-update-cmd` | `core.hooksPath .githooks`, plus four vendor patches (NativePHP, two for Pest mutate, PHPUnit source mapper) | **Adapted** | `hooksPath` is kept. The two Pest patches become this package's own `pest:patch` (ADR-0004), which its CI enables. NativePHP does not apply. The source-mapper patch fixes a wildcard `<source>` include the package does not use. |
   | `require-dev`: ergebnis/composer-normalize, ergebnis/phpstan-rules, laravel/pint, pestphp/pest, pestphp/pest-plugin-arch, phpstan/phpstan and its strict and deprecation rules, rector/rector, roave/security-advisories, shipmonk/composer-dependency-analyser, shipmonk/phpstan-rules, spaze/phpstan-disallowed-calls, tomasvotruba/cognitive-complexity, tomasvotruba/type-coverage | at the versions locked on companion `main` | **Copied** | Generic PHP tooling |
   | `require-dev`: larastan/larastan, pestphp/pest-plugin-laravel, monolog/monolog, saloonphp/saloon, modules/dx | | **Not applicable** | Laravel, NativePHP or companion code. The package adds infection/infection, symfony/yaml, nette/neon and async-aws/s3 instead, to test its adapters. |
   | `config` | `allow-plugins`, `optimize-autoloader`, `platform.php: 8.5.0`, `sort-packages`, `process-timeout: 0` | **Copied** | |
   | `phpstan.neon` | Decision 2, plus larastan | **Adapted** | Less larastan. Paths are `src`, `tests`, `phpstan` and `bin`. |
   | spaze disallowed lists | B1 clock, B2 randomness, B3 filesystem, B4 `sleep`, S1 `passthru`/`shell_exec`, Q1 `ini_set`, Q2 superglobals, P3, P4 reflection, L3 non-`mb_` string functions, L4–L6, C5 `else`/`elseif`, C3 bare exceptions; A2–A4 Laravel helpers; S3 `withoutVerifying` | **Adapted** | Scoped with `allowIn` to the layers of ADR-0001. The clock is allowed only behind PSR-20 in `Cli`. Filesystem only in adapters that own files. Process execution only in the runner and git adapters. Superglobals and `getenv` only in `Cli`. A2–A4 and S3 have nothing to bind to. |
   | Own PHPStan rules, generic: NoBroadCatchRule, NoDynamicAccessRule, NoEmptyRule, NoLateStaticBindingRule, NoMagicMethodRule, NoMagicNumberRule, NoManyMethodsRule (20 methods), NoManyReturnsRule (3 returns), NoNestedTernaryRule (with `??` on a subscript), NoPositionalBooleanArgumentRule, PreferSprintfOverConcatRule, PreferSprintfOverInterpolationRule, NoWeakenedTlsRule | `phpstan/Rules/` | **Copied** | Generic. The interpolation rule's message loses its mention of a translator. |
   | NoOutputRule | echo and print only in tests | **Adapted** | A CLI prints, so output is allowed in `Cli` and in the console reporter only. |
   | NoNullsafeInDomainRule | `?->` banned in a hard-coded list of companion modules | **Adapted** | The list is `Core`. |
   | NoSubstitutedWireValueRule, NoUserFacingLiteralRule, ServiceProviderBindsOnlyRule | SDK wire values, translations, Laravel providers | **Not applicable** | Nothing here for them to bind to |
   | `rector.php` | PHP sets from Composer's PHP; prepared sets deadCode, codeQuality, typeDeclarations, privatization, instanceOf, earlyReturn; `importNames`; cache | **Adapted** | The same sets. The companion's four scoped skips are about its Screens, contract tests and one dx file, so they are dropped. |
   | `pint.json` | `per` plus strict types, strict comparison, `final_class`, global namespace imports, native function invocation, ordered elements and imports, trailing commas | **Copied** | |
   | `composer-dependency-analyser.php` | shipmonk, prod and dev paths derived from module manifests | **Adapted** | `src` is prod and `tests` is dev. The libraries in `suggest` are declared optional. For a library, this is what keeps `require` honest for everyone who installs it. |
   | Deptrac, composer-require-checker, composer-unused | none | **Not applicable** | The companion has none. Pest arch and the dependency analyser cover the same ground. |
   | Pest arch presets | `arch()->preset()->php()`, `->preset()->security()` | **Copied** | |
   | Hand-written arch rules | A1–A5 framework and boundary bans, B1 ambient calls kept out of the kernel, C3, H1 vague suffixes, H2 `Interface`/`Abstract` affixes, H6 exception naming, D4 closed sets as enums, every class final, no debugging calls | **Adapted** | A1–A5 and B1 become the layer rules of ADR-0001, with the ambient ban on `Core`. The others are copied. |
   | `ModuleApiTest` | C1 outcomes, C2 no nullable returns, D1 no arrays, D2 primitives only in named constructors, D3 no `mixed`, M1–M3, over each module's `Api` namespace | **Adapted** | Applied to the public API of decision 7 instead of `Api` namespaces |
   | `ModuleBoundariesTest` | E1/E2, generated from module kinds | **Not applicable** | There are no modules. ADR-0001's layer rules take its place. |
   | `TestConventionsTest` | G1 no mocking foreign types, G5 `expect()` only, G6 no committed `->only()` and no reason-less `->skip()`, H7 behavioural test names, G10 no duplicate helpers | **Copied** | |
   | `CommentsTest` | K1–K4: comments state the current situation (markers such as *previously* and *TODO* refused), docblocks only where a type cannot speak, said once, about a symbol | **Copied** | |
   | `TheRulesAreRealTest` | R1: every documented rule has an artifact carrying its identifier, and every identifier in an artifact is documented | **Copied** | |
   | `EveryMeasuredTreeDeclaresItsFloorsTest` | G7: every measured tree declares its coverage and mutation floors in its nearest manifest | **Adapted** | The gate itself refuses a tree without a floor (ADR-0003). The arch test checks the coverage floor. |
   | `TheSuiteCannotGoQuietTest` and `phpunit.xml`'s `failOn*` settings | G11: a diagnostic fails the run, including one raised under `@` | **Copied** | |
   | `NoRequirementIdInACommentTest` | GOV-R6: no requirement IDs in comments; test titles exempt | **Adapted** | Widened. The package has no requirement IDs at all, so the check covers comments and test names. That enforces the companion's convention of no IDs in test names, which the companion itself does not enforce. |
   | `tests/Guards` | R2: every rule that claims to be enforced has a planted violation, planted in a throwaway copy of the checkout, and must be refused by the analyser or the named test. Built from `Fixture` (`analyser`, `analyserInPlace`, `suite`, `isolatedSuite`, `edit`, `direct`, `notDrivable` with a required reason) and `Fixtures`, `Proof`, `Rules` and `Violations/*` | **Adapted** | The same pattern and support classes, for one package rather than modules |
   | `ARCHITECTURE.md` | Rule tables `\| \| Rule \| Enforced by \|`, with the vocabulary `phpstan: own rule`, `arch`, `test`, `review`, `planned`. Also `## Refused, and why` and `## Patterns`. | **Adapted** | The same format, holding the rules that apply here. R1 and Guards read it as they do in the companion. |
   | `tests/Floors` and `test:coverage --min=100` | G9: each tree's line coverage floor, read from clover | **Adapted** | One tree, `src`, at 100 |
   | SonarCloud | `sonar-project.properties`, the `sonar` job and the spec `sonar-gate` with `allowed-open: 0` | **Adapted** | A new SonarCloud project for this repository, fed the clover and JUnit reports the tests job writes |
   | typos, actionlint, markdownlint, lychee | `typos.toml`, `.markdownlint.jsonc`, `lychee.toml`, run by `lemonfiber/spec`'s `hygiene.yml` | **Copied** | The config files are copied, and the jobs are called from `lemonfiber/spec` pinned by SHA. |
   | gitleaks, osv-scanner | `lemonfiber/spec`'s `security.yml` | **Copied** | The same reusable workflow |
   | commitlint, DCO, attribution | `lemonfiber/spec`'s `commitlint.yml`, `dco.yml` and `attribution.yml`, plus `.githooks/commit-msg` | **Copied** | The same reusable workflows and hook |
   | spec-check, workflow-pins, spec-references, labeler | `lemonfiber/spec` | **Not applicable** | They check lemonfiber requirement IDs and pins against the lemonfiber spec, and this package has neither. |
   | Signed commits | branch protection `required_signatures` | **Copied** | |
   | CODEOWNERS | `* @lessevv` | **Copied** | |
   | Dependabot | Composer weekly in groups, Actions daily with a cooldown, commit prefix `build` | **Adapted** | The same cadence, with groups for this package's tools |
   | `.githooks` | `pre-commit` (Pint on staged files), `commit-msg`, `pre-push` (refuses a direct push to `main`) | **Copied** | |
   | `.editorconfig`, `.gitattributes` | `/.github export-ignore` | **Adapted** | A library's archive is what users download, so `tests`, `docs`, `phpstan`, `.github` and dotfiles are `export-ignore` as well. |
   | CodeQL | `codeql.yml`, `analyze` required | **Adapted** | CodeQL has no PHP analysis. It runs for the workflows, the reusable workflow and `action.yml`. |
   | OpenSSF Scorecard | `scorecard.yml` | **Copied** | A public package is what Scorecard exists for |
   | Mutation jobs | `mutation-scope`, a `mutation` matrix, `mutation-gate`, driven by `scripts/mutation.php` | **Adapted** | They become this package's reusable workflow: plan, shards and verdict (decision 8) |

4. **The companion's code conventions, and what enforces each here.**

   | Convention | Enforced by |
   |------------|-------------|
   | No `else` or `elseif` | spaze `disallowedControlStructures` |
   | Booleans are passed by name | NoPositionalBooleanArgumentRule |
   | `sprintf` over interpolation and concatenation | PreferSprintfOverInterpolationRule, PreferSprintfOverConcatRule |
   | No null for absence: no nullable types in `Core` or the public API. Absence is a type or an outcome, and adapters convert a library's null before it crosses a port. | arch (C2, widened to `Core`), NoNullsafeInDomainRule |
   | No `??` on an array subscript | NoNestedTernaryRule |
   | Outcomes, not exceptions, across a boundary (ADR-0001) | arch (C1 over ports), NoBroadCatchRule, the C3 bare-exception ban |
   | No arrays in the public API | arch (D1) |
   | Primitives only in named constructors | arch (D2) |
   | No `mixed` in public signatures | arch (D3), with type coverage at 100 |
   | Comments state current fact | CommentsTest (K1–K4) and review |
   | No requirement IDs in comments or test names | The widened ID check (decision 3) |
   | At most 20 methods per class, constructor included | NoManyMethodsRule, alongside at most three returns per function and cognitive complexity of 8 per function and 30 per class |
   | Every class final, readonly where it can be; no magic numbers; no vague suffixes | arch, NoMagicNumberRule, H1 |

5. **The generic rules stay internal in v1.** The copied PHPStan rules live in
   `phpstan/`, autoloaded for development only and left out of the dist
   archive. So do the arch helpers and the Guards support. Shipping them would
   make them public API, with their own versioning and support, and the
   package's product is the gate. They are kept free of anything
   gate-specific, so they can later move into a rules package that both this
   package and the companion require. That is a separate decision.

6. **CI mirrors the companion's layout, as far as a library needs it.**

   | Job | Runs | Required |
   |-----|------|----------|
   | what changed | Skips the PHP jobs for a documentation-only change | yes |
   | dco, attribution, commitlint | `lemonfiber/spec` reusable workflows | yes |
   | hygiene | actionlint, pins, typos, links, invite, shared-files, markdown (`lemonfiber/spec`) | yes |
   | security | gitleaks, osv-scanner (`lemonfiber/spec`) | yes |
   | rules | `pest --testsuite=Arch` | yes |
   | the rules refuse violations | `composer test:guards` | yes |
   | checks | validate, normalize, lint, analyse, refactor, deps, audit | yes |
   | tests and coverage | `test:report` and `test:floors`, with the highest dependencies | yes |
   | lowest dependencies | the suite after `composer update --prefer-lowest` | yes |
   | runner contracts | the Runner contract suite (ADR-0004) against the lowest and highest supported Pest and Infection | yes |
   | mutation testing | the package's own plan, shards and verdict, through the local reusable workflow and `uses: ./`, with the verdict as the check | yes |
   | sonarcloud, gate | SonarCloud and its quality gate | yes |
   | analyze | CodeQL for the workflows, the reusable workflow and the action | yes |
   | scorecard | OpenSSF Scorecard | no |
   | full mutation | the weekly scheduled full run | no |

7. **The public API, and semantic versioning from 1.0.0.** What semver
   protects:
   - the CLI's commands, options and exit codes (0 passed, 1 failed, 2 cannot
     judge);
   - the config: the PHP builder, the JSON Schema, and the meaning of every
     setting;
   - the file formats: the baseline, the JSON report and the ledger;
   - the action's and the reusable workflow's inputs and outputs;
   - the eight ports, `Extension`, `Extensions`, `Configurable`, the `Core`
     value types the ports use, and the `#[Holds]` attribute.

   Everything else is marked `@internal`, and that includes the plan file,
   which only passes between jobs of one version. A removal is deprecated with
   a warning for at least one minor release first, and happens only in a major.

8. **The package, its CI and its GitHub integration live in one repository,
   `nightworksio/php-mutation-gate`.** GitHub's Marketplace asks for a public
   repository with a single `action.yml` at its root, a name no other action,
   organisation or category uses, and two-factor authentication on the
   publishing account. A repository's own workflows do not stop it being
   listed. Two ways in are offered:
   - **A composite action, `action.yml` at the root**, listed on the
     Marketplace as `mutation-gate`, or as `PHP Mutation Gate` if that name is
     taken, with branding.
     - **Inputs**, each typed: `config`, `php-version`, `runner`, `shard`,
       `changed-since` (the base), `budget`, `reports` and the proof `cache`
       store.
     - **Outputs:** `verdict`, `scores` (JSON), the paths of the reports it
       wrote, and `plan` (JSON).
     - **What it does.** It sets up PHP at `php-version` with a coverage
       driver, installs the project's Composer dependencies, restores the proof
       ledger from the Actions cache and runs the gate. Without `shard` it runs
       the whole gate in one job: plan, run and verdict. It then writes line
       annotations and the step summary, posts the sticky PR comment and saves
       the ledger when the run may write it (ADR-0007). With `shard` it runs
       that one shard of a plan, which is how the reusable workflow uses it. It
       checks that the project's installed gate has its own major version, and
       stops if not.
   - **A reusable workflow, `.github/workflows/mutation-gate.yml`**, called with
     `workflow_call`, for sharded runs. It has three jobs:
     - a plan job, which writes the matrix;
     - one matrix job per shard, each running the action with `shard`;
     - an aggregate job, which runs the verdict, the check a branch protects.
       It keeps the ledger in the Actions cache under a key derived from the
       proofs, writes the annotations and step summary, and posts the sticky PR
       comment (ADR-0009).

     A fourth job publishes the badge and trend (ADR-0009). It runs only on the
     default branch, and it is the only job that asks for `contents: write`.
   - **Pinning.** Every action either one uses is pinned by SHA with its tag in
     a comment, as `lemonfiber/spec` ADR-0009 requires, and the README's
     examples pin this repository by SHA the same way.
   - **Versions.** The action and the workflow are versioned with the package:
     each release tag (`v1.4.0`) is theirs too. A release workflow moves the
     major tag (`v1`) to each new release.
   - **Dogfooding.** The package's own CI runs its self-gate through the local
     reusable workflow and `uses: ./`, so both are exercised on every pull
     request by the code in that pull request.

9. **Commits and releases.**
   - **Every commit:**
     - is signed;
     - carries `Signed-off-by` (DCO);
     - uses a conventional-commit subject;
     - carries no AI co-author trailer (the attribution check).
   - **A commit that implements a decision** names it in a `Spec:` trailer:
     `Spec: 0006`, or several numbers. The commit-msg hook checks that each
     number is an ADR in `docs/decisions`.
   - **The first release is 1.0.0.** It is tagged only when all 20 features are
     implemented, tested, gated at 100% and documented in the README. Nothing
     is tagged before that: no 0.x and no release candidates. Until then the
     companion can require `dev-main`.
   - **Each release** is a signed tag on `main` and a GitHub release with notes
     drawn from the conventional commits. Packagist follows through GitHub's
     webhook.

10. **Supported versions at 1.0.0.**

    | Dependency | Supported | Tested in CI |
    |------------|-----------|--------------|
    | PHP | 8.5 and later 8.x | 8.5; each later minor added when it is released and green |
    | Pest | `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0 (`conflict` outside the range the contract suite has passed) | lowest and highest in range |
    | Infection | `infection/infection` ~0.35.0, with PHPUnit 12 or 13 | 0.35.x lowest and highest, each with PHPUnit 12 and 13 |
    | Coverage driver | pcov or Xdebug | pcov |
    | `symfony/console`, `symfony/process`, `symfony/http-client`, `psr/clock` | ^7.4 \|\| ^8.0 for Symfony, ^1.0 for `psr/clock` | lowest and highest |
    | Optional: `symfony/yaml`, `nette/neon`, `async-aws/s3` | the current major of each at release | lowest and highest |

    A runner release outside these ranges is added in a minor release, once
    its contract suite passes. Infection is still 0.x, so each of its minor
    releases is treated as a potential break.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Gate the package with a released version of itself** | The gate would judge each change with the previous code, so a change to the gate would only be proved one release later. Using the same commit proves a change by itself. |
| **A floor below 100 for the package** | It would ask users for a rigour the package does not keep. The companion declares 100 for all its trees but four, and each of those four says in its manifest why it is 0. |
| **Own copies of the hygiene, security, DCO and commitlint workflows** | Two places to keep in step with every change. `lemonfiber/spec` is public, its reusable workflows are generic, and pinning by SHA keeps its changes deliberate. The lemonfiber-specific checks are the ones left out. |
| **Ship the generic PHPStan rules and arch presets in this package** | Makes them public API of a mutation tool, and ties their versioning to the gate's. A rules package of their own is the place, if they are shared. |
| **Renaming the copied rules to slugs** (`no-else`, `method-cap`) instead of the companion's rule IDs (`C5`, `H3`) | R1 and Guards match on `<ID> —` at the start of each message, and the rules are copied as they are, so the IDs stay. They identify code rules, not requirements: the widened check refuses requirement IDs (`<AREA>-R<n>`), and a rule ID in a test's description stays allowed, as it is in the companion. |
| **Deptrac for layer rules** | The companion does not use it. Pest arch and PHPStan already run in the suite, and Guards proves each of their rules refuses a violation. |
| **Release candidates before 1.0.0** | The approved decision is no release before all 20 features. A release candidate is a release people depend on. |
| **A PHAR instead of a Composer package** | Would avoid Symfony version conflicts in consuming projects, but the Pest adapter's plugin, the `#[Holds]` attribute and extension discovery all need the package to be in the project's autoloader. Broad Symfony ranges are the answer to conflicts. |

## Consequences

**The package is its own first user.** Every rule it asks of users (100% floor,
ratchet, proofs, reach) is exercised on its own code on every pull request.

**Contributors meet the companion's bar**, with the same tools, scripts, hooks
and failure messages. Someone who has worked in one repository can work in the
other.

**The 20 features gate the first release, not a date.** The README lists them,
and each points at the ADR that decides it.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-eight-ports.md): the layers the arch rules enforce
- [ADR-0003](0003-a-floor-only-rises.md): the floors the package holds itself to
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the runner contract suite and `pest:patch`
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): what the reusable workflow runs
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the workflow posts and publishes
- `lemonfiber/spec` ADR-0009: pinning every action to a SHA
