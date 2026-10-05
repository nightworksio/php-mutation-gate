# ADR-0011: The package holds itself to the gate it ships, and to the standards of the in-house project

**Status:** Accepted
**Date:** 2026-09-29

## Context

A mutation gate that is not mutation-tested asks users for a rigour it does not
show. The maintainer's decisions for this package are:

- it gates itself at a 100% floor;
- it runs PHPStan at max and keeps its core isolated with arch tests;
- it ships a composite GitHub Action (`action.yml`) in the same repository;
- it has no release at all until every feature the README lists is in
  (ADR-0013, decision 17).

The package also takes the code-quality, testing, tooling and architecture
standards of the in-house project whose gate it generalises, reusing whatever
carries over. And it stands alone: nothing in its CI, its dev tooling, its
Composer dependencies, its documentation or its runtime reaches into another
organisation's repository.

This ADR inventories the in-house project's toolchain. Its main parts are:

- Composer scripts and dev dependencies;
- a `phpstan.neon` with 18 rules of its own;
- `rector.php`, `pint.json` and `composer-dependency-analyser.php`;
- 59 arch test files;
- the Guards suite, which plants a violation of every rule and requires it to
  be refused;
- an `ARCHITECTURE.md` with each rule beside the test that enforces it;
- a floors suite, SonarCloud, and shared reusable workflows, kept in another
  repository, for hygiene, security, commitlint and attribution.

Much of that is generic PHP discipline and carries over as it is. Some of it is
about Laravel, NativePHP or the project's modules, and does not.

## Decision

1. **The package gates itself with itself, at 100.**
   - The config declares one tree, `src`, at floor 100 with new code at 100,
     and one tree for each first-party plugin's `plugins/<name>/src`, at the
     same floor (ADR-0021).
     Uncovered mutants count (`uncovered: count`), so every line must be both
     covered and mutation-tested.
   - The declared floor is 100, so the baseline cannot hold it lower.
   - The runner is Pest.
   - The package's CI calls its own reusable workflow from the same commit
     (`uses: ./.github/workflows/mutation-gate.yml`), whose shard jobs run the
     action from that commit too (decision 8). Every change to the gate, the
     action or the workflow is judged by the version it introduces.
   - The verdict job is a required check.
   - A weekly scheduled full run is part of it (ADR-0005).

2. **Static analysis is the in-house project's, less Laravel.** `phpstan.neon`
   runs at `level: max` with:
   - `phpstan-strict-rules` and `phpstan-deprecation-rules`;
   - `ergebnis/phpstan-rules` with `allRules: true`, with the in-house
     project's disabled list (named arguments and nullable defaults allowed);
   - `shipmonk/phpstan-rules`;
   - `tomasvotruba/type-coverage` at 100 for declare, return, param, property and
     constant;
   - `tomasvotruba/cognitive-complexity` at 30 per class and 8 per function;
   - `spaze/phpstan-disallowed-calls` with its four bundled lists;
   - `checkUninitializedProperties`, `checkImplicitMixed`,
     `checkBenevolentUnionTypes`, `checkTooWideReturnTypesInProtectedAndPublicMethods`
     and `reportAnyTypeWideningInVarTag`.

   There is no PHPStan baseline and no `ignoreErrors`, ever.

3. **The in-house toolchain, piece by piece.** Each piece is *copied* (taken
   as it is), *adapted* (taken and changed for a single library), or *not
   applicable*. Where the in-house piece ran from another repository, this
   package keeps its own copy.

   | Piece | In the in-house project | Here | Why |
   |-------|-------------------------|------|-----|
   | `composer ci` script chain | validate `--strict`, `validate:modules`, normalize, `normalize:modules`, audit, lint, analyse, refactor, deps, `test:guards`, `test:report`, `test:floors`, `test:mutation` | **Adapted** | The same chain less the two `:modules` steps, since there are no modules. `test:mutation` runs `bin/mutation-gate`. |
   | Other scripts | `analyse`, `lint`/`lint:fix`, `refactor`/`refactor:fix`, `deps`, `test`, `test:coverage`, `test:report`, `test:floors`, `test:guards`, with `scripts-descriptions` | **Copied** | Same names, same meaning, so the same muscle memory works in both repositories |
   | `post-install-cmd` / `post-update-cmd` | `core.hooksPath .githooks`, plus four vendor patches (NativePHP, two for Pest mutate, PHPUnit source mapper) | **Adapted** | `hooksPath` is kept. The two Pest patches become this package's own `pest:patch` (ADR-0004), which its CI enables. NativePHP does not apply. The source-mapper patch fixes a wildcard `<source>` include the package does not use. |
   | `require-dev`: ergebnis/composer-normalize, ergebnis/phpstan-rules, laravel/pint, pestphp/pest, pestphp/pest-plugin-arch, phpstan/phpstan and its strict and deprecation rules, rector/rector, roave/security-advisories, shipmonk/composer-dependency-analyser, shipmonk/phpstan-rules, spaze/phpstan-disallowed-calls, tomasvotruba/cognitive-complexity, tomasvotruba/type-coverage | at the versions the in-house project locks | **Copied** | Generic PHP tooling |
   | `require-dev`: larastan/larastan, pestphp/pest-plugin-laravel, monolog/monolog, saloonphp/saloon and the project's own packages | | **Not applicable** | Laravel, NativePHP or in-house code. The package adds infection/infection, symfony/yaml, nette/neon and async-aws/s3 instead, to test its adapters. |
   | `config` | `allow-plugins`, `optimize-autoloader`, `platform.php: 8.5.0`, `sort-packages`, `process-timeout: 0` | **Copied** | |
   | `phpstan.neon` | Decision 2, plus larastan | **Adapted** | Less larastan. Paths are `src`, `tests`, `phpstan` and `bin`. |
   | spaze disallowed lists | the clock, randomness, the filesystem, `sleep`, `passthru` and `shell_exec`, `ini_set`, superglobals, reflection, non-`mb_` string functions, `else`/`elseif`, bare exceptions, Laravel helpers and an HTTP client's TLS switch | **Adapted** | Scoped with `allowIn` to the layers of ADR-0001: the filesystem, the standard streams, processes, the network, the environment, the system clock and waiting only in adapters and `Cli`, and no random numbers anywhere in `src`. The Laravel helpers and the TLS switch have nothing to bind to. |
   | Own PHPStan rules, generic: NoBroadCatchRule, NoDynamicAccessRule, NoEmptyRule, NoLateStaticBindingRule, NoMagicMethodRule, NoMagicNumberRule, NoManyMethodsRule (20 methods), NoManyReturnsRule (3 returns), NoNestedTernaryRule (with `??` on a subscript), NoPositionalBooleanArgumentRule, PreferSprintfOverConcatRule, PreferSprintfOverInterpolationRule, NoWeakenedTlsRule | in the project's own rules directory | **Copied** | Generic, into `phpstan/Rules/`. The interpolation rule's message loses its mention of a translator. |
   | NoOutputRule | `echo` and `print` only in tests | **Adapted** | No `echo` or `print` anywhere in `src`. The CLI and the console reporter write through `symfony/console`'s output. |
   | The rule against `?->` in the domain | `?->` banned in a hard-coded list of modules | **Adapted** | As NoNullsafeInCoreRule, over `Core` |
   | Rules for SDK wire values, translations and Laravel providers | | **Not applicable** | Nothing here for them to bind to |
   | `rector.php` | PHP sets from Composer's PHP; prepared sets deadCode, codeQuality, typeDeclarations, privatization, instanceOf, earlyReturn; `importNames`; cache | **Adapted** | The same sets. The in-house project's four scoped skips are about its own screens, contract tests and one file, so they are dropped. |
   | `pint.json` | `per` plus strict types, strict comparison, `final_class`, global namespace imports, native function invocation, ordered elements and imports, trailing commas | **Copied** | |
   | `composer-dependency-analyser.php` | shipmonk, prod and dev paths derived from module manifests | **Adapted** | `src` is prod and `tests` is dev. The libraries in `suggest` are declared optional. For a library, this is what keeps `require` honest for everyone who installs it. |
   | Deptrac, composer-require-checker, composer-unused | none | **Not applicable** | The in-house project uses none of them. Pest arch and the dependency analyser cover the same ground. |
   | Pest arch presets | `arch()->preset()->php()`, `->preset()->security()` | **Copied** | |
   | Hand-written arch rules | framework and boundary bans, ambient calls kept out of the kernel, bare exceptions, vague suffixes, `Interface`/`Abstract` affixes, exception naming, closed sets as enums, every class final, no debugging calls | **Adapted** | The framework and boundary bans and the ambient-call ban become the layer rules of ADR-0001, with the ambient ban on `Core`. The others are copied. |
   | The module API test | outcomes, no nullable returns, no arrays, primitives only in named constructors, no `mixed`, over each module's API namespace | **Adapted** | Applied to the public API of decision 7, and the ban on nullable types widened to all of `Core` |
   | The module boundaries test | generated from module kinds | **Not applicable** | There are no modules. ADR-0001's layer rules take its place. |
   | The test conventions test | no mocking foreign types, `expect()` only, no committed `->only()` and no reason-less `->skip()`, behavioural test names, no duplicate helpers | **Copied** | |
   | The comments test | comments state the current situation (markers such as *previously* and *TODO* refused), docblocks only where a type cannot speak, said once, about a symbol | **Copied** | As `CommentsTest` |
   | The test that the rules are real | every documented rule has an artifact carrying its identifier, and every identifier in an artifact is documented | **Copied** | As `TheRulesAreRealTest` |
   | The test that every measured tree declares its floors | every tree declares its coverage and mutation floors in its nearest manifest | **Adapted** | The gate itself refuses a tree without a floor (ADR-0003). The arch test checks the coverage floor. |
   | The test that the suite cannot go quiet, and `phpunit.xml`'s `failOn*` settings | a diagnostic fails the run, including one raised under `@` | **Copied** | As `TheSuiteCannotGoQuietTest` |
   | The requirement-ID check | no requirement IDs in comments; test titles exempt | **Adapted** | Widened. The package has no requirement IDs at all, so the check covers comments and test names. That enforces the convention of no IDs in test names, which the in-house project only states. |
   | `tests/Guards` | every rule that claims to be enforced has a planted violation, planted in a throwaway copy of the checkout, and must be refused by the analyser or the named test. Built from `Fixture` (`analyser`, `analyserInPlace`, `suite`, `isolatedSuite`, `edit`, `direct`, `notDrivable` with a required reason, and `audit` and `coverage`, whose guards CI runs as the `ci-only` group) and `Fixtures`, `Proof`, `Rules` and `Violations/*` | **Adapted** | The same pattern and support classes, for one package rather than modules |
   | `ARCHITECTURE.md` | Rule tables `\| \| Rule \| Enforced by \|`, with the vocabulary `phpstan: own rule`, `arch`, `test`, `review`, `planned`. Also `## Refused, and why` and `## Patterns`. | **Adapted** | The same format, holding the rules that apply here under this package's own rule IDs (`C5`, `H3`, …). `TheRulesAreRealTest` and Guards read it. |
   | `tests/Floors` and `test:coverage --min=100` | each tree's line coverage floor, read from clover | **Adapted** | One tree, `src`, at 100 |
   | SonarCloud | a project, its properties file, a `sonar` job, and a gate job that allows no open issue | **Adapted** | A SonarCloud project of this repository's own, fed the clover and JUnit reports the tests job writes. Its token is the `SONAR_TOKEN` secret. The `sonar` job and its gate, allowing no open issue, are required from the start. |
   | typos, actionlint, markdownlint, lychee | config files, run by a shared hygiene workflow in another repository | **Adapted** | The config files are copied. The jobs are this repository's own `hygiene` workflow. |
   | gitleaks, osv-scanner | a shared security workflow in another repository | **Adapted** | This repository's own `security` workflow |
   | commitlint, attribution | shared workflows in another repository, plus `.githooks/commit-msg` | **Adapted** | This repository's own workflows and the scripts they run, plus its own `.githooks/commit-msg` |
   | Signed commits | branch protection `required_signatures` | **Copied** | The ruleset on `main` requires signed commits |
   | CODEOWNERS | the one maintainer | **Copied** | `* @lessevv` |
   | Dependabot | Composer weekly in groups, Actions daily with a cooldown, commit prefix `build` | **Adapted** | The same cadence, with groups for this package's tools |
   | `.githooks` | `pre-commit` (Pint on staged files), `commit-msg`, `pre-push` (refuses a direct push to `main`) | **Copied** | |
   | `.editorconfig`, `.gitattributes` | `/.github export-ignore` | **Adapted** | A library's archive is what users download, so `tests`, `.docs`, `phpstan`, `.github` and the other dotfiles are `export-ignore` as well. |
   | CodeQL | `codeql.yml`, `analyze` required | **Adapted** | CodeQL has no PHP analysis. It runs for the workflows, the reusable workflow and `action.yml`. |
   | OpenSSF Scorecard | `scorecard.yml` | **Copied** | A public package is what Scorecard exists for |
   | Mutation jobs | a scope job, a matrix of shards and an aggregating job, driven by the in-house script | **Adapted** | They become this package's reusable workflow: plan, shards and verdict (decision 8) |

4. **The code conventions, and what enforces each here.** The rule IDs are this
   package's own, held in `ARCHITECTURE.md`.

   | Convention | Enforced by |
   |------------|-------------|
   | No `else` or `elseif` | spaze `disallowedControlStructures` (C5) |
   | Booleans are passed by name | NoPositionalBooleanArgumentRule (D5) |
   | `sprintf` over interpolation and concatenation | PreferSprintfOverInterpolationRule, PreferSprintfOverConcatRule (H5) |
   | No null for absence: no nullable types in `Core` or the public API. Absence is a type or an outcome, and adapters convert a library's null before it crosses a port. | arch (C2, over the API surface and all of `Core`), NoNullsafeInCoreRule (C8) |
   | No `??` on an array subscript | NoNestedTernaryRule (C9) |
   | Outcomes, not exceptions, across a boundary (ADR-0001) | arch (C1 over ports), NoBroadCatchRule (C6), the bare-exception ban (C3) |
   | No arrays in the public API | arch (D1) |
   | Primitives only in named constructors | arch (D2) |
   | No `mixed` in public signatures | arch (D3), with type coverage at 100 |
   | Comments state current fact | `CommentsTest` (K1) and review |
   | No requirement IDs in comments or test names | The widened ID check (decision 3) and arch (H7) |
   | At most 20 methods per class, constructor included | NoManyMethodsRule (H3), alongside at most three returns per function (H8) and cognitive complexity of 8 per function and 30 per class |
   | Every class final, readonly where it can be; no magic numbers; no vague suffixes | arch (D7), NoMagicNumberRule (D6), arch (H1) |

5. **The generic rules stay internal in v1.** The copied PHPStan rules live in
   `phpstan/`, autoloaded for development only and left out of the dist
   archive. So do the arch helpers and the Guards support. Shipping them would
   make them public API, with their own versioning and support, and the
   package's product is the gate. They are kept free of anything
   gate-specific, so they can move into a rules package of their own.
   That is a separate decision.

6. **CI, as far as a library needs it.** Every job below is this repository's
   own, and every action it uses is pinned by a full commit SHA.

   | Job | Runs | Required |
   |-----|------|----------|
   | what changed | Skips the PHP jobs for a documentation-only change | yes |
   | commitlint, attribution, description | Every commit's conventional subject and trailers, the title and the body; in the `pr` workflow, which also runs on an edited title or body, where `description` checks the `Spec:` numbers and a breaking change's *Migration* section (ADR-0019) | yes |
   | hygiene | actionlint, typos, lychee (links), markdownlint and zizmor | yes |
   | scripts | `python3 -m unittest` over the deciding halves of `.github/scripts` (ADR-0019) | yes |
   | security | gitleaks and osv-scanner | yes |
   | rules | `pest --testsuite=Arch` | yes |
   | docs | the Docs suite: every example, the generated reference and the message slugs (ADR-0018) | yes |
   | the rules refuse violations | `composer test:guards` | yes |
   | checks | validate, normalize, lint, analyse, refactor, deps, audit | yes |
   | tests and coverage | `test:report` and `test:floors`, with the highest dependencies | yes |
   | lowest dependencies | the suite after `composer update --prefer-lowest` | yes |
   | runner contract | one leg per runner and resolution, side by side, each with its own install and suite: the Runner contract suite (ADR-0004) or the StaticChecker contract suite (ADR-0020) against the lowest supported Pest, Infection, PHPUnit, Mago, PHPStan or Psalm, each analyser lowest with the other packages as high as it allows, or the highest the fixtures' committed locks hold, which Dependabot moves | no |
   | runner contracts | every leg of runner contract passed, with what each leg's suite reported as its evidence | yes |
   | mutation testing | the package's own plan, shards and verdict, through the local reusable workflow, with its `verdict` job as the check | yes |
   | sonar, sonar gate | SonarCloud's analysis, and its quality gate with no open issue allowed | yes |
   | evidence | every job's evidence, gathered into the one artifact the bot reads (ADR-0019) | no |
   | analyze | CodeQL for the workflows, the reusable workflow and the action | yes |
   | scorecard | OpenSSF Scorecard | no |
   | full mutation | the weekly scheduled full run | no |
   | runner canary | daily, `canary.yml`: the Runner and StaticChecker contract suites against the newest Pest, Infection, PHPUnit, Mago, PHPStan and Psalm releases, red when a pinned release `composer.json` refuses passes (ADR-0017), and against the newest release of every native runner's framework (ADR-0027) | no |
   | phar | builds the PHAR and runs its smoke suite, publishing nothing (ADR-0022) | yes |
   | benchmark | `bench.yml`, on demand and monthly: the gate against plain Pest and Infection on four open-source projects (ADR-0017) | no |
   | the contributor bot | `bot-*.yml`: the relay, the explainer, the commands, the checklist and the release draft (ADR-0019) | no |

7. **The public API, and semantic versioning from 1.0.0.** What semver
   protects:
   - the CLI's commands, options and exit codes (0 passed, 1 failed, 2 cannot
     judge);
   - the config: the PHP builder, the JSON Schema, and the meaning of every
     setting;
   - the file formats: the baseline, the JSON report, the ledger, and the plan
     as `plan --ci=json` prints it;
   - the problems output's line format (ADR-0015);
   - `doctor`'s JSON output (ADR-0017);
   - the message slugs and the troubleshooting links built from them
     (ADR-0018);
   - the action's and the reusable workflow's inputs, outputs, secrets and job
     names;
   - the ten ports (ADR-0020), the `Mutator` layer and its testing kit
     (ADR-0021), `Extension`, `Extensions`, `Configurable`, the `Core`
     value types the ports use, and the `#[Holds]` attribute.

   Everything else is marked `@internal`, and that includes
   `.mutation-gate/plan.json` and the shard result files, which only pass
   between jobs of one version. A removal is deprecated with
   a warning for at least one minor release first, and happens only in a major.

8. **The package, its CI and its GitHub integration live in one repository,
   `nightworksio/php-mutation-gate`.** GitHub's Marketplace asks for a public
   repository with a single `action.yml` at its root, a name no other action,
   user, organisation or category uses, and two-factor authentication on the
   publishing account. A repository's own workflows do not stop it being
   listed. Two ways in are offered.
   - **A composite action, `action.yml` at the root**, listed on the
     Marketplace as `mutation-gate`, or as `PHP Mutation Gate` if that name is
     taken, with branding.

     An action's inputs are strings, so the Type column gives the format of
     the value. `cache` is the one boolean, and the reusable workflow declares
     it as `boolean` and the rest as `string`.

     | Input | Type | Default |
     |-------|------|---------|
     | `config` | path | none: the config is found as ADR-0002 says |
     | `php-version` | string | `8.5` |
     | `runner` | `pest`, `infection` or a registered name | none: the config's, or zero-config's |
     | `shard` | shard id | none: the whole gate runs |
     | `mode` | `auto`, `full` or `changed` | `auto`: change-scoped on pull requests and pushes, full on schedules, manual dispatches, releases and tags (ADR-0005 decision 2) |
     | `changed-since` | a git ref, or `last-passed` | the pull request's base on `pull_request`, `last-passed` on a push to the default branch; used only when the mode is change-scoped |
     | `budget` | duration | none |
     | `reports` | `<name>:<path>` lines, each added as `--report` | none |
     | `cache` | boolean | `true`: the ledger is kept in the Actions cache (ADR-0007) |

     | Output | What it holds |
     |--------|---------------|
     | `verdict` | `passed`, `failed` or `cannot-judge` |
     | `scores` | JSON: each tree's score, and the new code's |
     | `report-paths` | JSON: each written report's name and path |
     | `plan` | JSON: the plan, as `plan --ci=json` prints it |

     **What it does.** It sets up PHP at `php-version` with a coverage driver,
     installs the project's Composer dependencies, restores the proof ledgers
     from the Actions cache and runs the gate with the job's token as
     `GITHUB_TOKEN`. It checks that the project's installed gate has its own
     major version, and stops if not.
     - Without `shard` it runs the whole gate in one job: plan, run and
       verdict. It writes line annotations and the step summary, posts the
       sticky PR comment, and saves the ledger when the run may write it
       (ADR-0007).
     - With `shard` it runs that one shard of the plan in `.mutation-gate/`,
       which is how the reusable workflow uses it. Its outputs are then
       empty, because the verdict comes later.
   - **A reusable workflow, `.github/workflows/mutation-gate.yml`**, called with
     `workflow_call`, for sharded runs.
     - **Inputs** are the action's less `shard`, with the same types and
       defaults.
     - **Secrets**, all optional: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
       and `AWS_SESSION_TOKEN`, passed to every job that reads or writes an S3
       proof store; `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
       `MUTATION_GATE_WEBHOOK_URL` and `MUTATION_GATE_WEBHOOK_SECRET`, passed
       to the `verdict` job; and `OTEL_EXPORTER_OTLP_ENDPOINT` and
       `OTEL_EXPORTER_OTLP_HEADERS`, passed to every job (ADR-0016).
     - **Outputs** are `verdict`, `scores` and `plan`, as the action's. The
       reports are uploaded as the artifact `mutation-gate-reports`, and a
       baseline measured for trees with no floor as `mutation-gate-baseline`
       (ADR-0017).
     - **Jobs:**
       - `plan` sets up as the action does, restores the ledgers, runs
         `plan --ci=github` and uploads `.mutation-gate` as an artifact. The
         action has no plan-only or verdict-only mode, so this job and the
         verdict job run the CLI after the same setup steps;
       - `shard`, one matrix job per shard, checks out the calling repository,
         and this repository at `job.workflow_sha` (the commit the workflow
         was called at) into a directory of its own. It downloads the plan's
         artifact into `.mutation-gate/`, and runs the action from the
         checkout of this repository with `shard`, so the workflow and the action are
         always the same commit, and uploads its result file;
       - `verdict` runs whenever the run was not cancelled, even after a failed
         shard. It restores the ledgers and the files published last time,
         runs the verdict, writes the annotations and the step summary, posts
         the sticky PR comment (ADR-0009) and saves the ledger. It is the check
         a branch protects, shown as `<calling job> / verdict`;
       - `publish` pushes the badge and trend to the `mutation-gate` branch
         (ADR-0009). It runs only on the default branch, and it is the only job
         that asks for `contents: write`.
     - **Permissions.** Every job has `contents: read`. `plan` also has
       `actions: read` and `pull-requests: read`, because the GitHub
       `ChangeSource` reads workflow runs and pull requests to prove that a
       merged pull request's run passed (ADR-0005). `verdict` has
       `actions: read` and `pull-requests: write`, for the same reason and for
       the comment. `publish` has `contents: write`. The one-step action needs
       `contents: read`, `actions: read` and `pull-requests: write` in its one
       job. A job that reaches an S3 store through an OIDC role adds
       `id-token: write`, and the role that can write the default branch's
       prefix trusts only an environment restricted to the default branch,
       or this workflow's `job_workflow_ref`, never a bare `ref`
       (ADR-0007, ADR-0019).
   - **Pinning.** Every action either one uses is pinned by a full commit SHA
     with its tag in a comment, and Dependabot moves the pins. The README's
     examples pin this repository the same way.
   - **Versions.** The action and the workflow are versioned with the package:
     each release tag (`v1.4.0`) is theirs too. A release workflow runs the
     gate over the package in `mode: full`, and only when that passes moves the
     major tag (`v1`) to the new release.
   - **Dogfooding.** The package's own CI runs its self-gate through the local
     reusable workflow, whose shard jobs run the action from the same commit
     (decision 1). So the workflow and the action's shard mode are exercised on
     every pull request by the code in that pull request. The action's one-job
     mode runs the same setup steps and the same CLI.

9. **Commits and releases.**
   - **Every commit:**
     - uses a conventional-commit subject;
     - carries no AI co-author trailer (the attribution check).
   - **Every commit on `main` is signed.** `main` takes only squash commits,
     which GitHub signs, so a contributor's own commits need not be
     (ADR-0018).
   - **A commit that implements a decision** names it in a `Spec:` trailer:
     `Spec: 0006`, or several numbers. The commit-msg hook checks that each
     number is an ADR in `.docs/decisions`.
   - **The first release is 1.0.0.** It is tagged only when every feature the
     README lists (ADR-0013, decision 17) is implemented, tested, gated at 100% and documented in the README. Nothing
     is tagged before that: no 0.x and no release candidates. Until then a
     project can require `dev-main`.
   - **Each release** is a signed tag on `main` and a GitHub release with notes
     drawn from the conventional commits: the version's `CHANGELOG.md`
     section, generated and then edited before the tag (ADR-0018). The
     release workflow publishes nothing for a tag whose signature the
     repository's allowed signers do not verify, or that points off `main`,
     and a tag ruleset lets only the maintainer create `v*` tags
     (ADR-0019). Packagist follows through GitHub's webhook.

10. **Supported versions at 1.0.0.**

    | Dependency | Supported | Tested in CI |
    |------------|-----------|--------------|
    | PHP | 8.5 and later 8.x | 8.5; each later minor added when it is released and green |
    | Pest | `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0 (`conflict` outside the range the contract suite has passed), on the PHPUnit 13 release each Pest version pins | lowest and highest in range |
    | Infection | `infection/infection` ~0.35.0, with PHPUnit 12 or 13 | 0.35.x lowest and highest, each with PHPUnit 12 and 13 |
    | Native runners | PHPUnit 13.2.0 and later (ADR-0023); Codeception, PhpSpec and Testo (ADR-0027), each at the versions its contract suite has passed | lowest and highest in range |
    | Coverage driver | pcov or Xdebug | pcov |
    | `symfony/console`, `symfony/process`, `symfony/http-client`, `psr/clock` | ^7.4 \|\| ^8.0 for Symfony, ^1.0 for `psr/clock` | lowest and highest |
    | Optional: `symfony/yaml`, `nette/neon`, `async-aws/s3` | ^7.4 \|\| ^8.0, ^3.4 and ^3 | lowest and highest |

    A runner release outside these ranges is added in a minor release, once
    its contract suite passes. Infection is 0.x, so each of its minor
    releases is treated as a potential break.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Gate the package with a released version of itself** | The gate would judge each change with the previous code, so a change to the gate would only be proved one release later. Using the same commit proves a change by itself. |
| **A floor below 100 for the package** | It would ask users for a rigour the package does not keep. The in-house project declares 100 for all its trees but four, and each of those four says in its manifest why it is 0. |
| **Calling the in-house project's shared workflows** for hygiene, security, commitlint and attribution | Ties this package's CI to another organisation's repository: a change there would change what this repository's checks do, with no commit here. Own copies cost keeping them current, which Dependabot does for their actions. |
| **Ship the generic PHPStan rules and arch presets in this package** | Makes them public API of a mutation tool, and ties their versioning to the gate's. A rules package of their own is the place, if they are shared. |
| **Renaming the copied rules to slugs** (`no-else`, `method-cap`) instead of rule IDs (`C5`, `H3`) | `TheRulesAreRealTest` and Guards match on `<ID> —` at the start of each message, and the rules are copied as they are, so IDs stay. They identify code rules, not requirements: the widened check refuses requirement IDs (`<AREA>-R<n>`), and a rule ID in a comment beside an expectation stays allowed. |
| **Deptrac for layer rules** | Pest arch and PHPStan already run in the suite, and Guards proves each of their rules refuses a violation. |
| **Release candidates before 1.0.0** | The approved decision is no release before every feature the README lists. A release candidate is a release people depend on. |
| **A PHAR instead of a Composer package** | Would avoid Symfony version conflicts in consuming projects, but the Pest adapter's plugin, the `#[Holds]` attribute and extension discovery all need the package to be in the project's autoloader. Broad Symfony ranges are the answer to conflicts. ADR-0022 supersedes this row: a signed PHAR and a container image ship *beside* the Composer package, and the PHAR refuses a Pest project, whose plugin only the Composer package installs. |
| **Referring to the action from the reusable workflow by tag** (`nightworksio/php-mutation-gate@v1`) | The workflow and the action could then be different commits, and the package's own CI would judge a change to the action with the released one. Checking out the workflow's own commit keeps them one version. |

## Consequences

**The package is its own first user.** Every rule it asks of users (100% floor,
ratchet, proofs, reach) is exercised on its own code on every pull request.

**Contributors meet the in-house project's bar**, with the same tools, scripts,
hooks and failure messages. Someone who has worked in one repository can work in
the other.

**The package stands alone.** Its CI and tooling change only through commits to
this repository.

**The features the README lists gate the first release, not a date.** Each
points at the ADR that decides it.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the layers the arch rules enforce
- [ADR-0003](0003-a-floor-only-rises.md): the floors the package holds itself to
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the runner contract suite and `pest:patch`
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): what the reusable workflow runs
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the workflow posts and publishes
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the release gate covers every feature the README lists
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the problems output as public API, and the CI templates pinned to the package's own workflows
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the reusable workflow's secrets for chat alerts and OpenTelemetry
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the runner canary, the benchmark, `doctor`'s JSON and the measured-baseline artifact
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the `docs` job, message slugs, signed commits on `main`, and the changelog and release notes
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the `pr` and `scripts` jobs, zizmor, the contributor bot, tag verification, and the OIDC roles
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the plugins' trees, and the mutator SDK as public API
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the PHAR and the image, the `phar` job, and what a release publishes
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the native PHPUnit runner
- [ADR-0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): the Composer plugin published from `plugins/composer`, and the `.pre-commit-hooks.yaml` ids
- [ADR-0027](0027-codeception-phpspec-and-testo-get-native-runners.md): the Codeception, PhpSpec and Testo runners
