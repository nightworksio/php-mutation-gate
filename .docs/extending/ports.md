# Other ports

Beside [runners](runners.md), [reporters](reporters.md),
[proof stores](proof-stores.md), [config loaders](config-loaders.md) and
[mutators](mutators.md), the core reaches the world through these ports
([ADR-0001](../decisions/0001-a-framework-free-core-behind-nine-ports.md)).
Each is an interface under `NightWorksIO\MutationGate\Port`, and its doc
blocks say each answer in full.

## Static checker

A static analyser asked about one mutant at a time: Mago, PHPStan, Psalm, or
one an extension adds with `withStaticChecker()`, chosen by
`staticCheck.tool`
([ADR-0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md)).
It answers its identity and the configuration it resolves, which the proof key
reads; its findings over the original files; and its findings over a mutant
analysed in place of its original, alone or side by side. A check that cannot
run leaves the mutant to its tests and never kills it, and whether a mutant's
findings reject it is the core's to say, from the findings its original does
not have.

## Tree source

Where the trees come from: `phpunit.xml`'s `<source>`, Composer's autoload,
or one an extension adds with `withTreeSource()`, chosen by `treeSource`. It
answers every tree, with the floor each declares and the package each belongs
to.

## CI plan

A CI's own way of running a plan, chosen by `ci.plan`, or by the CI a job
runs in where the config names none. It renders the plan in the CI's own
format, such as a matrix or a child pipeline; names the CI definitions that
run the gate, which reach everything when they change; and reads the run's
ref, whether it is a pull request and the default branch. Its registration,
`withCiPlan()`, also declares the CI's own credentials, which no runner hands
the project's tests whatever the config says, and the variable its CI marks
every job with.

## Preset

A layer of config with a name, laid before the config file so the project's
own settings win, registered with `withPreset()` and chosen by `preset`
([presets](../using/configuration.md#presets)).

## Cost model

What mutating a unit costs a runner, which decides only which shard a unit
goes to, so a wrong cost slows a run and never changes a verdict. The gate
runs its own, `learned`, which learns each unit's cost from earlier shards
([ADR-0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md)).
An extension can register one with `withCostModel()`, and no config chooses
it.

## Change source and repository

The repository as version control sees it: what changed since a revision,
what a file held at one, and where the checkout stands. The gate runs its own,
`github` under GitHub Actions and `git` anywhere else; where one cannot tell,
the gate reaches everything. An extension can register one with
`withChangeSource()` and `withRepository()`, and no config chooses it.

## Processes

How the runners start programs, one to its end or several side by side, each
stopped with every process it started at its deadline or its silence limit.
It is the gate's own, and no extension registers one.
