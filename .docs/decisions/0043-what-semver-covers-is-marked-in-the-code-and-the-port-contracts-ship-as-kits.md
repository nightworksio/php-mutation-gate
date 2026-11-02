# ADR-0043: What semver covers is marked in the code, and the runner and proof store contracts ship as kits

**Status:** Accepted
**Date:** 2026-11-01
**Amends:** [0001](0001-a-framework-free-core-behind-nine-ports.md): its API surface, and its moves only in a major
**Amends:** [0004](0004-pest-and-infection-behind-one-runner-port.md): its contract suite, for what every runner answers
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its deprecation before a major, while the major is 0
**Amends:** [0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): its SDK layer as public API, for `Mutator\Engine`

## Context

What the package promises its users and extension authors, read at source:

- **The promise is written where users do not read it.** ADR-0011's item 7
  lists what semver protects and says everything else is `@internal`.
  ADR-0001's consequences make moving any extension class a major release.
  `ARCHITECTURE.md` names the public layers. None of these ships in the
  Composer package, and no page under `.docs` says what semver covers.
- **The boundary is not marked.** 47 files carry `@internal`, each with a
  reason of its own, and none carries `@api`. Nothing checks either way, so
  an extension author's analyser reports nothing whatever they reach into.
- **The API surface misses what doc blocks name.** The D rules compute the
  surface as every class in the public layers and every core type their
  public signatures reach, by native types alone. `Extensions` registers each
  adapter as a `Closure`, and the options that closure is handed,
  `Core\Config\Options`, are written only in its doc block. So `Options`,
  which `writing-an-adapter.md` tells an author to read, is outside the
  surface. So is every type a collection yields, such as the `FailingTests`
  an `UnmutatedFailures` iterates, whose `mutants()` answers an array.
- **The Mutator layer holds machinery.** `Mutator\Engine` makes and applies
  mutants for the native runners. Each of its classes says `@internal the
  engine's own`, and no public signature reaches one.
- **The contract suites do not ship.** ADR-0004's item 7 puts the runner
  contract suite in `tests/Contract/Runner`, and the proof store suite is in
  `tests/Contract/ProofStore`. `/tests` is export-ignored, so an extension
  author's install holds neither, though `writing-an-adapter.md` points
  authors at the first. `ConfigLoaderContract` already ships in
  `src/Extension`: a final class whose static `failures()` answers every way
  a loader breaks the contract, which `tests/Contract/ConfigLoader` runs
  against every first-party loader (ADR-0002).
- **Semantic Versioning's item 4.** Major version zero is for initial
  development, and anything may change at any time.

## Decision

This amends ADR-0001's consequences, ADR-0004's item 7, ADR-0011's item 7 and
ADR-0021's item 1.

1. **Semver covers this surface.**
   - **The command line:** every command, option and argument, and the exit
     codes 0 passed, 1 failed and 2 cannot judge.
   - **The config:** every key and what it means, the PHP builder, and the
     JSON Schema `config:schema` writes.
   - **The JSON the gate writes:** each schema under `resources/`, the report,
     summary, tests report, `doctor`, `explain`, webhook and config schemas,
     and the problems output's line format (ADR-0015).
   - **The files it keeps:** the baseline and the ledger, and the plan as
     `plan --ci=json` prints it.
   - **Troubleshooting slugs**, and the links built from them (ADR-0018).
   - **The action's and the reusable workflow's** inputs, outputs, secrets and
     job names.
   - **The extension API:** every class in `Attribute`, `Port`, `Mutator`,
     `Config` and `Extension` but their machinery, and every core type their
     public faces reach, followed as far as it goes. A class's public face is
     its parents and interfaces, and every type its public methods and
     properties write, natively or in a doc block. The machinery is
     `Mutator\Engine` and `Extension\Checks`.

2. **Everything else is internal:** every other class, interface, trait and
   enum under `src` and each plugin's `src`, the console's wording, the HTML
   report's markup, and the files under `.mutation-gate` that pass between
   jobs of one version, such as `plan.json` and the shard results. A plugin's
   classes are internal; the mutator names a config writes are covered as
   config.

3. **What a release may change.**
   - While the major is 0, a minor release may break the covered surface. A
     change that does is a breaking commit, titled with `!`, whose
     `## Migration` section `description` requires, and the changelog, and so
     the release notes, carry it (ADR-0018).
   - A patch release never breaks it.
   - From 1.0.0, a break is a major release, and a removal is deprecated with
     a warning for at least one minor release first.

4. **The boundary is marked in the code and enforced.**
   - Every type outside the surface says `@internal` in its doc block, and no
     type on it does. PHPStan reports a use of an `@internal` type from
     outside the `NightWorksIO` root namespace, so an extension author's
     analyser says where they reach past the promise.
   - D13 in `ARCHITECTURE.md`: an Arch test computes the surface and fails
     where a type on it is marked or a type off it is not. The surface is
     computed, never listed, so a new port signature widens it in the same
     change.
   - C2, D1, D2 and D3 hold the same surface, so every type a doc block
     reaches passes no null, array or `mixed` across it either.
     `FailingTests` yields its mutants instead of answering an array.

5. **The runner and proof store contracts ship as kits** in `src/Extension`,
   beside `ConfigLoaderContract` and in its shape: a final class whose static
   methods answer every way an adapter breaks the contract, each said as a
   sentence, and none where it keeps it.
   - `ProofStoreContract` holds a store that keeps what it is handed with
     `failures()`, one opened read-only with `readOnlyFailures()`, one that
     leaves what it writes for `deliver` with `deliveringFailures()`, and one
     whose ledger for the branch `main` holds bytes that are no ledger with
     `unreadableFailures()`.
   - `RunnerContract::failures()` holds a runner to what every runner
     answers over one library. The library's code ships under
     `resources/contract/runner/src`. Its tests are the files `LibraryTest`
     lists, which the runner's author writes in the runner's own framework,
     each saying what it asserts. `LibraryChange` names each change the
     library allows, with the record every runner reports for it, and
     `RunnerLibrary` says how the runner spells each change's mutator, what
     its test files end in, and whether it reports how long each mutant took.
   - The checks a kit makes are machinery, in `Extension\Checks`, so the
     promise covers each kit's entry and the values it is handed, never how
     it checks.
   - `tests/Contract/ProofStore` and `tests/Contract/Runner` run the kits
     against the fake and every first-party adapter, so one contract holds
     first-party and third-party adapters alike. What only a first-party
     runner shows, such as Pest's patched groups or Infection's warning, stays
     in those suites.
   - Every first-party runner fixture holds the shipped library's code, byte
     for byte, which a unit test checks.

6. **A user-facing page says all this.** `.docs/reference/compatibility.md`
   lists the covered surface, what is internal and what each kind of release
   may change, and `writing-an-adapter.md` says how to run each kit.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **`@api` on the public types, and nothing on the rest** | PHPStan checks `@internal` and has no check for `@api`, so an author's analyser would report nothing, and a type left unmarked would read as internal by silence. |
| **`@internal` on the whole of `Core`, `Adapter` and `Cli`** | The ports' signatures reach most of `Core`. An author implementing `Runner` would see an analyser error on every value the port hands them. |
| **The surface by native types alone, as the D rules read it** | It leaves out `Options`, written only in the doc block of the closure `Extensions` registers, and every type a public collection yields. Both are what an extension author handles. |
| **Every class in the public layers, `Mutator\Engine` included** | The engine is the gate's own machinery, already marked `@internal the engine's own`, and no public signature reaches it. Promising it would freeze how the native runners make mutants. |
| **The kits' checks as public classes in `Extension`** | Each would be covered by the promise though no author calls it. They are many because PHPStan holds each class to a cognitive complexity of 30. |
| **A list of public classes kept by hand** | It drifts from the signatures it describes, and the D rules already compute the surface. |
| **Shipping `tests/Contract` by dropping its `export-ignore`** | The suites are Pest tests on the first-party fixtures and the gate's own test support. An author on PHPUnit or another framework could not run them. |
| **A testing package of its own, `nightworksio/mutation-gate-testing`** | A second version to keep in step with the ports it checks. `ConfigLoaderContract` already ships in the package. |
| **The runner library's code in the kit's doc block alone** | An author would copy prose, and nothing would hold the first-party fixtures to it. The shipped files are what every fixture is held to, byte for byte. |

## Consequences

**A user can read what an upgrade may break**, on a page that ships with the
docs, and a breaking minor says how to move.

**An extension author's analyser reports a reach past the promise**, and a
type the promise covers carries no mark.

**A change that widens the surface widens the promise in the same change.**
A new port signature that names a core type makes that type public, and D13
fails until its `@internal` mark goes.

**Most of `Core` is public**, because the ports hand it across. Narrowing the
ports is what narrows the promise.

**A third-party runner or store is held to the contract a first-party one
is**, with the kit its install already holds. A runner's author keeps a
small library in their own framework.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the layers, the ports and the API surface, which this amends
- [ADR-0002](0002-one-typed-config-from-several-formats.md): `ConfigLoaderContract`, the kit the new ones follow
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the runner contract suite, which this amends
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proof store
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the public API and semantic versioning, which this amends
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the changelog and release notes that carry a breaking change's migration
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the `Mutator` layer as public API, which this amends
- [ADR-0028](0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md): the Google Cloud Storage and Azure Blob stores the kit holds
