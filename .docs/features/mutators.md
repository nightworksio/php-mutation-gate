# Runners and mutators

The gate drives the test runner a project already has, and adds the mutators
a framework's code needs.

## Runners

| Runner | Who makes the mutants | Decided in |
|--------|-----------------------|------------|
| Pest | Pest's own `--mutate`, through `pestphp/pest-plugin-mutate` | [ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| Infection | Infection, with PHPUnit 12 or 13 | [ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| PHPUnit on its own | the gate, through a PHPUnit extension | [ADR-0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| PhpSpec, Codeception | the gate, through an extension of each | [ADR-0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| Testo | the gate, through a Testo plugin | [ADR-0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md), [ADR-0047](../decisions/0047-testos-runner-holds-a-minor-range-and-its-plugin-selects-stops-and-times.md) |

Every runner reports each mutant with the same statuses, and the gate judges
them all by the same rules, so a verdict means the same under each. Zero-config
chooses the runner that is installed: PHPUnit, PhpSpec, Codeception or Testo
only where neither Pest's mutation plugin nor Infection is, and Pest is not. A
run refuses a runner release it does not drive before it starts, and `doctor`
says so first
([ADR-0041](../decisions/0041-a-runner-release-the-gate-does-not-drive-is-named-before-the-run.md)).

## Mutator sets

| Set | What it mutates | Turned on |
|-----|-----------------|-----------|
| `default` | Pest's default set, and a mutator for each family it leaves out: removing a `throw`, and `public` to `protected` | always, under the native runners; Pest and Infection run their own mutators in its place |
| `security` | constant-time comparisons, password checks, escaping, input filtering and session regeneration | by every preset |
| `laravel` | gates, guards, escaping, validation, transactions, caches and dispatches | by the `laravel` preset |
| `symfony` | access checks, CSRF and password checks, forms, flushes and message dispatches | by the `symfony` preset |

`mutators.sets` turns sets on, and `mutators.except` turns single mutators
off. Installing a package never changes a score by itself
([ADR-0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md)).

Every mutator is written once against the gate's SDK, on php-parser 5, and
runs under every runner through a bridge the gate writes for it: under Pest
beside Pest's own mutators, under Infection beside the mutators the project's
Infection config turns on, and under the native runners in the gate's own
engine. A mutator tagged `security` makes **security mutants**, which are
held to a floor of their own ([floors and the score](floors.md)).

[Writing mutators](../extending/mutators.md) says how to write a set of your
own.

## Presets

A preset is a layer of config for a kind of project: `library`, `laravel` or
`symfony`, chosen from `composer.json` where the config names none. It sets
the trees to fall back on, the files every test reads, a longer timeout where
the tests boot a framework, and the mutator sets
([configuration](../using/configuration.md#presets)).
