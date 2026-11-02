# Writing an adapter

An adapter is a runner, reporter, config loader, store or other part the gate
chooses by name. A package can add its own, and a project then chooses it in
its config, by its registered name or by its class.

## Choosing an adapter in a config

A setting that names an adapter takes either a registered name (`"pest"`,
`"sarif"`) or a class with its options:

```json
{
    "reports": [
        { "use": "Acme\\Gate\\SlackReporter", "with": { "channel": "#ci" } }
    ]
}
```

A `use` with a backslash is a class, so a class in the global namespace is
written `"\\SlackReporter"`.

## Reading options

The adapter reads its options through
`NightWorksIO\MutationGate\Core\Config\Options`, one key at a time as a type:

- `$options->text(Key::of('channel'))` answers the text, `NotGiven`, or the
  problem at `channel`.
- `$options->path(Key::of('cache'))` names a path from the config file's
  directory, as the gate names its own, and answers a path that lands outside
  the project as a problem.

## Registering it

Packages that offer adapters are found through `extra.mutation-gate.extensions`
in their `composer.json`
([ADR-0001](../../decisions/0001-a-framework-free-core-behind-nine-ports.md)).
Each class named there implements
`NightWorksIO\MutationGate\Extension\Extension`, and its `extend()` answers
the registry with its adapters, presets and mutator sets added. An
extension's `Extensions` registry is made with the `Origin` of its package,
`NightWorksIO\MutationGate\Core\Registry\Origin`.

## What each kind must answer

What an adapter may use is the extension API, which
[compatibility](../../reference/compatibility.md) describes: every type it
names that does not say `@internal`. Its own tests hold it to its port's
contract with the kit in `NightWorksIO\MutationGate\Extension` that the
gate's own adapters are held to. Each kit's `failures()` answers every way
the adapter breaks the contract, one sentence each, and none where it keeps
it, so a test of any framework asserts that it answers none:

```php
expect([...ProofStoreContract::failures($store)])->toBe([]);
```

- **A config loader** decodes its format into JSON and reads it with
  `ConfigFile::read()`, so the gate's own definition judges every file.
  `ConfigLoaderContract::failures($loader, $fixtures, $extension)` holds it
  to what every loader answers. It reads one fixture of each case in the
  loader's format, from a directory the extension names: `valid`,
  `invalid`, `dated`, `up`, `adapter` and `broken`, and `unquoted` where the
  format can write a number as a mutant id. Its docblock says what each one
  writes.
- **A proof store** is handed to `ProofStoreContract` new, holding nothing:
  `failures($store)` for a store that keeps what it is handed,
  `readOnlyFailures($store)` for one opened read-only, and
  `deliveringFailures($store)` for one that leaves what it writes for
  `deliver`. `unreadableFailures($store)` takes a store whose ledger for the
  branch `main` holds bytes that are no ledger, and asks that it says the
  ledger is malformed.
- **A preset** is a layer of config, such as
  `Gate::configure()->…->layer(ProjectRoot::origin())` builds
  ([ADR-0002](../../decisions/0002-one-typed-config-from-several-formats.md)).
- **A runner** answers `behaviour()` with `RunnerBehaviour::standard()` unless
  it behaves otherwise, and answers `coverage()` for a `CoverageRun`, the
  tests it runs under coverage, and for a `CoverageRead`, the directory of a
  map another job wrote. `RunnerContract::failures($runner, $library)` holds
  it to what every runner answers, over a small library installed as a
  project of its own
  ([ADR-0004](../../decisions/0004-pest-and-infection-behind-one-runner-port.md)):
  1. Copy `vendor/nightworksio/mutation-gate/resources/contract/runner/src`
     into the library's `src`, and map the `Library\` namespace to it in the
     library's `composer.json`.
  2. Write the tests `LibraryTest` lists, in the runner's own framework, each
     doing what its docblock says. Between them they kill one change of
     `Money`, let one survive, run none of a third and never let the loop of a
     fourth end; and they let the change of `Held` survive under the group
     `holds:src/Held.php`, which a test outside that group would kill.
  3. Build the runner in the library's root, holding each mutant to a limit
     short enough that the endless loop times out.
  4. Say how the runner spells the library, and ask for its failures:

     ```php
     $library = RunnerLibrary::spelling(
         adds: 'Plus',
         large: 'GreaterThan',
         unused: 'Minus',
         drains: 'Decrement',
         held: 'Plus',
         testEnding: 'Test.php',
     );

     expect([...RunnerContract::failures($runner, $library)])->toBe([]);
     ```

     Each argument is the runner's name for the mutator that makes that
     `LibraryChange`. `RunnerLibrary::unmeasured()` is the library as a
     runner spells it that reports neither the line a mutant ends on nor how
     long it took.

To add an adapter to this package itself rather than to a package of your own,
see [CONTRIBUTING](../../../CONTRIBUTING.md#adding-an-adapter-a-preset-or-an-extension).
