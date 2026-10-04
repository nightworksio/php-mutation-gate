# ADR-0021: Mutators are written once against a public SDK, first-party sets live apart and can leave, and security mutants are held to a floor of their own

**Status:** Accepted
**Date:** 2026-09-30

## Context

The gate leaves making mutants to its runners (ADR-0001, ADR-0004), and each
runner has its own mutator API. Read in the vendored sources of
pest-plugin-mutate 5.0.2, Infection 0.35.5 and `infection/mutator` 0.4.1:

- **Pest's mutator is static.** `Pest\Mutate\Contracts\Mutator` has
  `nodesToHandle()`, `name()`, `set()`, `can(Node)` and
  `mutate(Node): Node|int`, so it makes at most one change per node.
  - `--mutator=<a,b,…>` takes fully qualified class names and expands
    `MutatorSet` classes. It **replaces** the configured list rather than
    adding to it.
  - Pest's traverser runs php-parser's `NameResolver` with `replaceNodes:
    false`, so names carry a `resolvedName` attribute, and a
    `ParentConnectingVisitor`.
  - Pest re-parses a file once per mutation per mutator, and skips a mutator
    whose node classes the file does not contain.
  - Pest ships a proof-of-concept `LaravelSet` of two mutators of
    `Str::upper`.
- **Infection's mutator is an instance.** `Infection\Mutator\Mutator` has
  `getDefinition()`, `getName()`, `canMutate(Node)` and
  `mutate(Node): iterable`, which may yield several changes per node.
  - A custom mutator is enabled by its class name as a key of the config's
    `mutators`, found with `class_exists` and constructed with no arguments.
  - Infection offers a mutator only nodes inside a class method or on its
    signature: it does not traverse outside a class, and it skips plain
    functions. So a class constant, a property, an attribute or top-level
    code is never offered.
  - Its `NameResolver` also runs with `replaceNodes: false`.
- **Both runners mutate php-parser 5 trees**, and both find a mutator by a
  class name that must load in the runner's own process.
- **`Core`, `Port`, `Config` and `Extension` name nothing outside the
  package but PHP and `Psr\Clock`** (ADR-0001 decision 5, rule A1), so none
  of those public layers can name a php-parser node.
- **Presets are config fragments with a name** (ADR-0008 decision 5), each a
  typed layer of config laid before the config file (ADR-0002 decisions 1
  and 5).
- **Frameworks and security.** A framework app's riskiest code is framework
  calls: an authorisation check, a validation rule, a transaction. A
  security defence (an escape, a password check, a constant-time comparison)
  is exactly the code whose survivor matters most, and a tree's score can hide
  it among thousands of mutants.

## Decision

### The SDK

1. **A mutator is written once, against php-parser 5, in a public layer,
   `NightWorksIO\MutationGate\Mutator`.**
   - The layers are, in order: `Core`, `Attribute`, `Port`, `Mutator`,
     `Config`, `Extension`, `Adapter\<Name>`, `Cli`. This amends ADR-0001
     decision 1 from seven layers to eight.
   - The `Mutator` layer, and only it, may name `PhpParser`. This amends
     ADR-0001 decision 5's first rule, A1 in `ARCHITECTURE.md`.
   - `nikic/php-parser` ^5.9 is in `require`, since every runner process
     loads the SDK.
   - The layer is public API. This amends ADR-0011 decision 7.

2. **The interface.**

   ```php
   namespace NightWorksIO\MutationGate\Mutator;

   use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
   use PhpParser\Node;

   interface Mutator
   {
       /** Its name in reports and ignores, `<set>/<Name>`, such as `laravel/GateAllowsToTrue`. */
       public function name(): MutatorName;

       /** The family whose hint a survivor gets (ADR-0009 decision 7). */
       public function family(): MutatorFamily;

       /** What it is about, such as `security`. */
       public function tags(): Tags;

       /** The node classes it looks at; no other node is ever offered to it. */
       public function handles(): NodeClasses;

       /** The one change for this node, or none. It never changes the node it is given. */
       public function mutate(Node $node): Node|Removal|Unchanged;

       /** A sentence for its survivors, or its family's. */
       public function hint(): Hint|FamilyHint;
   }
   ```

   - A mutator makes **at most one change per node**. A mutator with two
     variants is two mutators, so it maps directly onto both runners.
   - It is constructed with no arguments, as an `Extension` is.
   - `Removal` becomes Pest's `NodeTraverser::REMOVE_NODE` and a yielded
     `Node\Stmt\Nop` under Infection.
   - A `MutatorSet` is a named, typed list of mutator classes.

3. **Sets are registered by name, and enabled by config.**
   - `Extensions::withMutators(Name $set, MutatorSet $mutators)` adds an
     extension point, *mutator set*. This amends ADR-0001 decision 4.
   - `mutators.sets`, a list of set names, `[]` by default plus what presets
     add, turns sets on. `mutators.except`, a list of mutator names, turns
     single ones off.
   - An unknown set the config file names is exit 2 with the nearest name
     (ADR-0002 decision 6). Installing a package never changes a score by
     itself.
   - `default` in `mutators.sets` is a config error, exit 2: that set is
     always on, and Pest and Infection run their own mutators in its place.
   - `mutators.except` names mutators held by a set in `mutators.sets` or
     by the default set, so the PHPUnit runner can turn off a single one of
     the default set's. A name neither holds is exit 2, with the nearest
     name where one is close.

4. **The gate writes a bridge for each runner.**
   - For each enabled mutator the gate writes one bridge class per runner,
     all of a runner's into `.mutation-gate/mutators/<runner>/bridges.php`,
     each delegating to the SDK class. A bridge's class is the SDK class's
     own name under `NightWorksIO\MutationGateBridge\Pest\` or
     `NightWorksIO\MutationGateBridge\Infection\`. The generator writes
     class names from the registry, so it joins the files ARCHITECTURE.md's
     P2 allows dynamic names.
   - **Pest:** the adapter names the file in `MUTATION_GATE_MUTATORS`, and
     the package's plugin, which boots in Pest's parent and in every
     mutant's child, loads it. The file registers each bridge in
     pest-plugin-mutate's `MutatorMap::$map` under each node class its
     mutator handles and each php-parser subclass of one, since Pest drops a
     mutator the map does not hold and looks a mutator up by a node's exact
     class. That map is a public static of a class without a stability
     promise, covered by the contract suite and the `conflict` pin.
     - `--mutator` replaces Pest's list, so a run of every mutator passes
       `--mutator=Pest\Mutate\Mutators\Sets\DefaultSet,<bridges>`, and a
       narrowed run names each mutator it applies, a bridged one by its
       bridge's class. Pest 5 configures its mutators only on the command
       line, so no project setting is lost.
   - **Infection:** the config the adapter writes adds each bridge,
     `"<bridge class>": true`, to `mutators` beside the project's own
     settings, and keeps `"@default": true` where the project sets none. Its
     `bootstrap` is the file of bridges, which declares them and then
     includes the project's own bootstrap. Infection loads `bootstrap`
     before it resolves `mutators`.
   - The runner contract suite runs a registered mutator through each
     bridge under the real runner.
   - This amends ADR-0004 decisions 3 and 4.

5. **A custom mutant looks the same under every runner.**
   - Its name is the SDK name, such as `laravel/GateAllowsToTrue`, under both
     runners. The adapters map a bridge's class back to it.
   - That name is the mutator's full name in the gate's id (ADR-0004
     decision 2), in `ignores.entries` (`{"path": …, "mutator":
     "laravel/GateAllowsToTrue"}`), and in every report.
   - Its family and hint are its own. ADR-0009 decision 7's table test covers
     registered mutators: one with `MutatorFamily::None` says so.
   - This amends ADR-0004 decision 2, whose full name is otherwise the Pest
     class name or the Infection name.

6. **A mutator may handle nodes one runner never offers.** Under Infection, a
   mutator whose node classes are all ones Infection never offers is named
   once at `plan`: *under Infection, `symfony/RemoveIsGrantedAttribute` sees
   no node*. The Runner contract suite pins what each runner offers.

7. **Every input to a custom mutant is keyed.**
   - `mutators.sets` and `mutators.except` **affect results**, in key item 3
     (ADR-0007 decision 2.3).
   - A registering package's version is in `installed.json`, item 5. A set
     inside this package moves with the gate's version, item 2.
   - A mutator class a project keeps under a test directory joins item 7, as
     files that define the runner do.
   - This amends ADR-0007 decisions 2.3 and 2.7.

8. **Authors get a testing kit.**
   `NightWorksIO\MutationGate\Mutator\Testing\Mutates` takes a mutator and a
   PHP snippet, and returns the diffs both bridges produce, so an author can
   assert them without installing either runner. The package's own sets are
   tested with it. It is public API.

9. **A mutator is trusted as an extension is.** It comes from Composer's
   `extra` or the config's `extensions` (ADR-0001 decision 4), and runs in the
   runner's process, which already runs the project's tests.
   `--no-extensions` turns off every set but those the first-party packages
   register: the gate's own and the plugins under `plugins/`, which count as
   first party while they ship here (ADR-0023 decision 8). A mutator's
   `handles()` stays narrow, because Pest re-parses a file once per mutation
   per mutator.

### First-party sets that can leave

10. **Each first-party set is a package in all but publication, under
    `plugins/`.**
    - The sets are `plugins/laravel/`, `plugins/symfony/` and
      `plugins/security/`. Each has its own `composer.json`
      (`nightworksio/mutation-gate-laravel`, `…-symfony`, `…-security`),
      requiring `nightworksio/mutation-gate` ^1 and `nikic/php-parser` ^5.9,
      with its own `autoload`, its own `extra.mutation-gate.extensions`, and
      `src/` and `tests/` inside.
    - Their namespaces are `NightWorksIO\MutationGateLaravel\`,
      `NightWorksIO\MutationGateSymfony\` and
      `NightWorksIO\MutationGateSecurity\`, outside the gate's own root.
    - The root `composer.json` maps those namespaces to `plugins/*/src/` in
      its own `autoload`, and lists the three extension classes in its own
      `extra.mutation-gate.extensions`. The directories ship in the root
      package's archive.
    - A set leaves by moving its directory to a repository of its own and
      deleting its autoload and extension lines from the root. No file
      inside it changes.

11. **Three rules keep the boundary, each refusing a planted violation
    (ADR-0011).**
    - **A6:** nothing under `src/` names `NightWorksIO\MutationGateLaravel\*`,
      `NightWorksIO\MutationGateSymfony\*` or `NightWorksIO\MutationGateSecurity\*`.
    - **A7:** a set names only PHP, `PhpParser`, the `Mutator` layer,
      `Extension\Extension`, `Extension\Extensions` and the `Core` values
      those signatures reach, such as `Core\Config\Name` and
      `Core\Mutant\MutatorFamily`, and never another set.
    - **A8:** each set's `composer.json` validates on its own, and
      `composer-dependency-analyser` run over the set alone finds every
      package it uses required. A set's tests use only the testing kit and
      fixtures inside the set.
    - The package's own gate holds four trees at 100: `src`,
      `plugins/laravel/src`, `plugins/symfony/src` and
      `plugins/security/src`. PHPStan, Rector, Pint and the Arch suite read
      the plugin paths too. This amends ADR-0011 decisions 1 and 3.

12. **Presets turn the sets on by name, softly.**
    - `library` adds `mutators.sets: ["security"]`, `laravel` adds
      `["laravel", "security"]`, and `symfony` adds `["symfony",
      "security"]`. This amends ADR-0008 decision 5: the presets table gains a
      row for mutator sets.
    - A set a preset's layer names and nobody registered is skipped, with one
      line naming the package to install: *the laravel preset turns on the
      mutator set "laravel", which is not installed: `composer require --dev
      nightworksio/mutation-gate-laravel`*.
    - The same name in the config file is exit 2. The gate names a set only
      as data, never as a class.
    - This amends ADR-0002 decision 5: which layer an entry comes from decides
      what a missing name means.

13. **A catalogue mutator matches on syntax and resolved names, and needs no
    framework installed.**
    - It matches a facade by its fully qualified name, a helper by its
      function name, a method called on `$this` in a class whose resolved
      parent is the framework's base class, or a method name distinctive on
      any receiver. Nothing depends on a receiver's type.
    - Each has a family, its own hint sentence, a fixture it mutates, a
      fixture it leaves alone, and a test that kills it. The sets require
      neither `laravel/framework` nor any `symfony/*` package.
    - A hint says what is missing in the framework's own terms, such as *No
      test checks that the action is refused when `Gate::allows()` says no*,
      filled from the diff as ADR-0009's are.

14. **The `laravel` set** (`NightWorksIO\MutationGateLaravel\Mutators`).
    "Pest only" marks a node Infection never offers.

    | Name | Change | Family | Tag |
    |------|--------|--------|-----|
    | `GateAllowsToTrue` | `Gate::allows(…)`, `Gate::check(…)` → `true`; `Gate::denies(…)` → `false` | Condition | security |
    | `RemoveAuthorize` | `$this->authorize(…)` (a class using `AuthorizesRequests`), `Gate::authorize(…)` removed | Removed call | security |
    | `RemoveAbort` | `abort_if(…)`, `abort_unless(…)` removed | Removed call | security when the code literal is 401 or 403 |
    | `AuthCheckToTrue` | `Auth::check()` → `true`, `Auth::guest()` → `false` | Condition | security |
    | `HashCheckToTrue` | `Hash::check(…)` → `true` | Condition | security |
    | `UnwrapEscape` | `e($x)` → `$x` | Unwrap | security |
    | `RemoveValidationRule` | one rule dropped from a `validate([...])` or `Validator::make(…)` rule list or `'a\|b'` string | Collection | none |
    | `FirstOrFailToFirst` | `->firstOrFail()` → `->first()`, `->findOrFail(…)` → `->find(…)` | Exception | none |
    | `UnwrapTransaction` | `DB::transaction(fn)` → `fn()` | Unwrap | none |
    | `UnwrapCacheRemember` | `Cache::remember($k, $t, fn)` → `fn()` | Unwrap | none |
    | `RemoveDispatch` | `event(…)`, `dispatch(…)`, `Event::dispatch(…)`, `Bus::dispatch(…)`, `Mail::…->send(…)`, `Notification::send(…)` removed | Removed call | none |
    | `RemoveMiddleware` | `$this->middleware(…)` in a controller's constructor removed | Removed call | security |
    | `RemoveGuardedEntry` | one entry dropped from a model's `$guarded` (Pest only) | Collection | security |

    `RemoveGuardedEntry` drops from `$guarded`, which widens mass assignment,
    and only a test that posts the guarded field notices. That is the
    vulnerability, so no mutator drops from `$fillable`.

15. **The `symfony` set** (`NightWorksIO\MutationGateSymfony\Mutators`).

    | Name | Change | Family | Tag |
    |------|--------|--------|-----|
    | `RemoveDenyAccess` | `$this->denyAccessUnlessGranted(…)` removed (an `AbstractController`) | Removed call | security |
    | `IsGrantedToTrue` | `->isGranted(…)` → `true` | Condition | security |
    | `CsrfValidToTrue` | `$this->isCsrfTokenValid(…)` → `true` | Condition | security |
    | `RemoveIsGrantedAttribute` | `#[IsGranted(…)]` removed (Pest only) | Removed call | security |
    | `RemoveAccessDeniedThrow` | `throw $this->createAccessDeniedException(…)` removed | Exception | security |
    | `PasswordValidToTrue` | `->isPasswordValid(…)` → `true` | Condition | security |
    | `FormValidToTrue` | `$form->isValid()` → `true` | Condition | none |
    | `RemoveFlush` | `->flush()` on an entity manager variable or property removed | Removed call | none |
    | `RemoveMessageDispatch` | `->dispatch(…)` on a `MessageBusInterface`-typed property or parameter removed | Removed call | none |

### Security mode

16. **A mutator tagged `security`, from any set, makes security mutants, and
    they are judged twice.**
    - A security mutant counts in its tree as every mutant does.
    - It is also judged in one more set, *security*, against a floor of its
      own, as new code is (ADR-0003 decision 8). So a security survivor fails
      the security floor even where it does not move its tree's score.

17. **The security floor ratchets like a tree's.**
    - The baseline gains a top-level `"security": {"floor": …}`, with
      `lowered` as trees have. The entry is part of the baseline's
      `"format": 1`. This amends ADR-0003 decisions 3, 6 and 7.
    - `security.floor`, a number from 0 to 100 with no default, is the
      declared minimum. `extra.mutation-gate.securityFloor` in a module's or
      package's `composer.json` sets it there, as `newCodeFloor` does
      (ADR-0005 decision 7).
    - A first CI run with no security floor measures it and hands the entry
      over, as ADR-0017 decision 6 does for trees. There is no default floor
      (ADR-0003 decision 9).
    - The security mutants on changed lines are already held to the new-code
      floor, 100 by default.
    - `security.floor` and `securityFloor` **judge only**.

18. **The generic `security` set.**

    | Name | Change | Family | Killed by |
    |------|--------|--------|-----------|
    | `HashEqualsToTrue` | `hash_equals($a, $b)` → `true` | Condition | a test with a wrong token |
    | `HashEqualsToIdentical` | `hash_equals($a, $b)` → `$a === $b` | Condition | only a test that reads the source |
    | `PasswordVerifyToTrue` | `password_verify(…)` → `true` | Condition | a test with a wrong password |
    | `UnwrapHtmlEscape` | `htmlspecialchars($x, …)`, `htmlentities($x, …)`, `strip_tags($x, …)` → `$x` | Unwrap | a test with markup in the input |
    | `UnwrapShellEscape` | `escapeshellarg($x)`, `escapeshellcmd($x)` → `$x` | Unwrap | a test with a shell metacharacter |
    | `UnwrapUrlEncode` | `urlencode($x)`, `rawurlencode($x)` → `$x` | Unwrap | a test with a reserved character |
    | `FilterVarToInput` | `filter_var($x, FILTER_VALIDATE_…)` → `$x` | Unwrap | a test with invalid input |
    | `RemoveSessionRegenerate` | `session_regenerate_id(…)` removed | Removed call | a test of the session id around login |

19. **A constant-time comparison is pinned by a test that reads the
    source.**
    - `HashEqualsToIdentical` behaves the same in every behavioural test:
      only timing differs. A test that reads source text kills it, as a Pest
      `arch()` test expecting `hash_equals` does.
    - Its hint: *Only a test that reads the source can pin a constant-time
      comparison: an arch test that expects `hash_equals`.*
    - `stub` writes that arch test under Pest, and under Infection a PHPUnit
      test that scans the file's tokens. This amends ADR-0015 decision 1.

20. **`run --only=security` audits the defences alone.**
    - It runs only the security-tagged mutators, through
      `MutationRequest::narrowedTo`, and judges only the security set. It
      says the trees were not judged.
    - Its results are keyed by the narrowed mutator list (decision 7), so
      they never stand in for a whole unit's proof.

21. **Security survivors are reported apart.**
    - The security set is a row of its own after new code in the console, in
      JSON (`security: {floor, declared, baseline, score, judgement, counts,
      mutants}`) and in JUnit (a `security` suite with one `floor` case).
    - SARIF results carry `properties.security: true`, at `error` level when
      the security set fails.
    - The PR comment lists security survivors in a section before the trees.
      Annotations rank them after changed lines. A chat alert names a failing
      security set.
    - This amends ADR-0009 decisions 2 and 3.
    - The catalogue is public, and lists defences, not flaws. A survivor in a
      public repository's PR comment points at an untested defence, which is
      the purpose.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The gate's own node model**, mirroring php-parser | A1 would hold as it is, at the cost of a second AST to write, document and keep in step with PHP's grammar. |
| **Declarative mutators in config** (`{"call": "Gate::allows", "becomes": "true"}`) | Cannot express a condition, a resolved name or a parent check, which framework mutators need. |
| **Each mutator written twice, once per runner** | Two implementations per mutator, the problem the SDK exists to remove. |
| **An interface without `tags` and `hint`** | Security mode would need a second way to name its mutators, and framework survivors would get only the family's sentence. |
| **Several changes per node**, with a declared variant count | Pest takes one per node, so its bridge would double in complexity for fewer classes to write. |
| **Every registered mutator on as soon as its package is installed** | A `composer require` would silently move every score. |
| **SDK authors shipping both bridges by hand** | Defeats the SDK. |
| **Appending the bridges to Pest's configured mutator list** through its configuration repository | Relies on an `@internal` class, and Pest 5 configures mutators only on the command line, so there is no project list to keep. |
| **The bridge's class name in ids and ignores** | Leaks a generated name, which changes if generation changes. |
| **Only node classes both runners offer** | Drops the attribute and property mutators the framework and security sets need. |
| **Only the config keys in the proof key** | A project's own mutator under `tests/` could change without moving any key. |
| **No testing kit** | Every author would build one, and the first-party sets would test through something private. |
| **Sets under the gate's own namespace root**, `NightWorksIO\MutationGate\Laravel\` | The layer rules would need an exemption for a sub-namespace, and "nothing names the set" would be a prefix rule with an exception. |
| **Sets under `src/Plugin/`** | Leaving would change their paths, and they would share `src`'s tooling until then. |
| **Separate repositories from the start** | A version matrix between the gate and its sets before 1.0, which ADR-0001 rejected for the runners. |
| **A path repository in development** | Tests the split for real, but a path repository cannot be published, so the root would need two ways to find one set. |
| **A missing set always exit 2** | A zero-config run of a Laravel project without the set's package would stop. |
| **A missing set always skipped** | A misspelt set in the config would silently do nothing, which ADR-0002 decision 6 refuses. |
| **Framework sets opt-in by config only** | Few projects would turn them on, and presets exist to give a framework app sensible defaults. |
| **Mutators that need a receiver's type**, from a static analyser | Ties mutation to the analyser of ADR-0020 and to the project's analyser config. |
| **The family sentence only for catalogue mutators** | True, and far less useful than the framework's own terms. |
| **Dropping entries from `$fillable` too** | Narrowing mass assignment is not the vulnerability, and the `$guarded` form models it. |
| **Security mutants kept out of tree scores** | The same line would be scored in two unrelated places, and a tree's score would hide its most important mutants. |
| **A fixed security floor of 100 with no baseline** | Every existing app would fail on the day it turned the mode on. |
| **A security floor over new code only** | The new-code floor already does that, and nothing old would be judged. |
| **The generic set inside `src/`** | The first user of the SDK would sit inside the code the SDK stands apart from. |
| **A constant-time finding from names instead of a mutant** | It would guess from a variable's name. |
| **Leaving the constant-time mutant out** | "Constant-time comparisons" would then mean only `HashEqualsToTrue`. |
| **No narrowed security run** | A scheduled audit of the defences would pay for every other mutant. |

## Consequences

**A mutator is written once and runs under both runners.** Its survivors
read the same in every report, and its ignores and ids do not depend on the
runner.

**php-parser is a dependency every user installs**, and the SDK layer is the
one public layer that names it.

**The framework and security sets are proven extractable on every pull
request.** Their rules and their own `composer.json` are checked with the
rest of the package.

**A Laravel or Symfony app's first run judges its framework calls**, and
says in the framework's words what the tests miss.

**Every app has a security score that only rises**, with its survivors
listed where a reviewer reads first.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the eighth layer, A1 for it, and the mutator-set extension point
- [ADR-0002](0002-one-typed-config-from-several-formats.md): soft preset entries
- [ADR-0003](0003-a-floor-only-rises.md): the security set and its baseline entry
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the bridges, the plugin's new job, and Infection's `bootstrap` and `mutators`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the sets in the key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): presets turn the sets on
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): families, hints, and the security row
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): four trees at 100, the tool paths, and the SDK as public API
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the arch-test stub
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the first run that hands over a floor
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the gate's own engine, built on this SDK
