# Extending the gate

A package of your own can add to the gate: a runner for another test
framework, a reporter, a proof store, a config format, a static analyser, a
CI, a preset or a set of mutators. The gate's own adapters are registered the
same way, and are held to the same contracts.

## An extension

An extension is a class that implements
`NightWorksIO\MutationGate\Extension\Extension`, constructed with no
arguments. Its `extend()` answers the registry it is handed with what it adds:

```php
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;

final readonly class AcmeExtension implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withReporter(Name::of('acme-chat'), AcmeChat::fromOptions(...));
    }
}
```

The gate finds it in either of two places
([ADR-0001](../decisions/0001-a-framework-free-core-behind-nine-ports.md)):

- `extra.mutation-gate.extensions` in the `composer.json` of an installed
  package, a list of class names, which is how a package offers one;
- `extensions` in the project's config, a list of class names, for an
  extension the project keeps in its own code.

`--no-extensions` loads only the gate's own and its first-party plugins, and
no third-party code. Two packages that register one name at one extension
point are refused, naming both.

## Extension points

| Extension point | Registered with | A config chooses it by | Its contract |
|-----------------|-----------------|------------------------|--------------|
| Runner | `withRunner()` | `runner` | [Runners](runners.md) |
| Reporter | `withReporter()` | `reports` | [Reporters](reporters.md) |
| Proof store | `withProofStore()` | `proofs.store` | [Proof stores](proof-stores.md) |
| Config loader | `withConfigLoader()` | the config file's extension | [Config loaders](config-loaders.md) |
| Mutator set | `withMutators()` | `mutators.sets` | [Mutators](mutators.md) |
| Static checker | `withStaticChecker()` | `staticCheck.tool` | [Other ports](ports.md#static-checker) |
| Tree source | `withTreeSource()` | `treeSource` | [Other ports](ports.md#tree-source) |
| CI plan | `withCiPlan()` | `ci.plan`, or the CI a job runs in | [Other ports](ports.md#ci-plan) |
| Preset | `withPreset()` | `preset` | [Other ports](ports.md#preset) |
| Cost model | `withCostModel()` | none: the gate takes its own, `learned` | [Other ports](ports.md#cost-model) |
| Change source, repository | `withChangeSource()`, `withRepository()` | none: the gate takes its own, `git`, or `github` under GitHub Actions | [Other ports](ports.md#change-source-and-repository) |

[Adapters](adapters.md) says how a config chooses an adapter, by its
registered name or by its class, and how an adapter reads its options.

## What you may use

What an extension may use is the extension API: every type under
`NightWorksIO\MutationGate\Attribute`, `Port`, `Mutator`, `Config` and
`Extension`, and every type their public methods, properties and doc blocks
name, followed through the types those name in turn, but
`Mutator\Engine` and `Extension\Checks`. Semantic versioning covers it, and
everything else says `@internal`
([compatibility](../using/compatibility.md),
[ADR-0043](../decisions/0043-what-semver-covers-is-marked-in-the-code-and-the-port-contracts-ship-as-kits.md)).

## Holding an adapter to its contract

Each port with a kit in `NightWorksIO\MutationGate\Extension` has its
adapters held to its contract by it, the gate's own among them: a kit's
`failures()` answers every way an adapter breaks the contract, one sentence
each, and none where it keeps it, so a test in any framework asserts that it
answers none:

```php
expect([...ProofStoreContract::failures($store)])->toBe([]);
```

The kits are `RunnerContract`, `ProofStoreContract` and
`ConfigLoaderContract`. Mutators are tested with
`NightWorksIO\MutationGate\Mutator\Testing\Mutates`.

## Examples

The first-party mutator sets under
[`plugins/`](https://github.com/nightworksio/php-mutation-gate/tree/main/plugins)
are extensions written against this API, each with a `composer.json` of its own:
`default`, `security`, `laravel` and `symfony`.
