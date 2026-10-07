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

- **A config loader** decodes its format into JSON and reads it with
  `ConfigFile::read()`, so the gate's own definition judges every file.
  `ConfigLoaderContract::failures()` holds it to what every loader answers. It
  reads one fixture of each case in the loader's format, from a directory the
  extension names: `valid`, `invalid`, `dated`, `up`, `adapter` and `broken`,
  and `unquoted` where the format can write a number as a mutant id. Its
  docblock says what each one writes.
- **A preset** is a layer of config, such as
  `Gate::configure()->…->layer(ProjectRoot::origin())` builds
  ([ADR-0002](../../decisions/0002-one-typed-config-from-several-formats.md)).
- **A runner** answers `behaviour()` with `RunnerBehaviour::standard()` unless
  it behaves otherwise, and answers `coverage()` for a `CoverageRun`, the
  tests it runs under coverage, and for a `CoverageRead`, the directory of a
  map another job wrote. The runner contract in `tests/Contract/Runner` holds
  it to both
  ([ADR-0004](../../decisions/0004-pest-and-infection-behind-one-runner-port.md)).

To add an adapter to this package itself rather than to a package of your own,
see [CONTRIBUTING](../../../CONTRIBUTING.md#adding-an-adapter-a-preset-or-an-extension).
