# Adapters

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
- `flag()`, `integer()`, `number()`, `paths()`, `texts()` and `object()` read
  the other types alike, and `problems()` says where the options as a whole
  are not an object.

## Registering it

An adapter is registered by an [extension](README.md#an-extension), as the
function that builds it from its options, under a name: a config then chooses
it by that name. An adapter a config names by its class implements
`NightWorksIO\MutationGate\Extension\Configurable`, whose
`fromOptions(Options $options)` builds it, or answers `Invalid` with every
problem, each reported at its path under the adapter's `with`.

## What each kind answers

Each kind's page says what its port asks of an adapter, and how its kit holds
an adapter to it: [runners](runners.md), [reporters](reporters.md),
[proof stores](proof-stores.md), [config loaders](config-loaders.md),
[mutators](mutators.md) and [the other ports](ports.md).

To add an adapter to this package itself rather than to a package of your own,
see [contributing](../../CONTRIBUTING.md#adding-an-adapter-a-preset-or-an-extension).

