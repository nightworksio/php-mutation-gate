# Compatibility

What an upgrade of the gate may change, and what it keeps. The package
follows [Semantic Versioning](https://semver.org) over the surface below, and
everything outside it is internal
([ADR-0043](../decisions/0043-what-semver-covers-is-marked-in-the-code-and-the-port-contracts-ship-as-kits.md)).

## What a release may change

| Release | The covered surface |
|---------|---------------------|
| A patch, such as 0.4.1 to 0.4.2 | Never breaks it. |
| A minor while the major is 0, such as 0.4 to 0.5 | May break it. Its release notes then carry a *Migration* section for each break, saying what to change. |
| A minor from 1.0.0 | Never breaks it. A removal is deprecated, with a warning, for at least one minor release first. |
| A major from 1.0.0 | May break it, with a *Migration* section for each break. |

Internal code may change in any release.

## What is covered

| Surface | Where it is described |
|---------|-----------------------|
| Every command, option and argument, and the exit codes: 0 passed, 1 failed, 2 cannot judge | [Command line](cli.md) |
| Every configuration key and what it means, the PHP builder, and the config's JSON Schema | [Configuration](configuration.md) |
| The JSON the gate writes, each under its schema in `resources/`: the report, the summary, the tests report, `doctor`, `explain`, the webhook and the config | [Reports](reports.md), [Command line](cli.md) |
| The problems output's line format | [Reports](reports.md) |
| The baseline and the ledger, and the plan as `plan --ci=json` prints it | [Files](files.md) |
| The troubleshooting slugs, and the links the gate prints to them | [Troubleshooting](../guide/troubleshooting.md) |
| The GitHub Action's and the reusable workflow's inputs, outputs, secrets and job names | [GitHub Actions](../guide/ci/github-actions.md) |
| The extension API: the namespaces `Attribute`, `Port`, `Mutator`, `Config` and `Extension`, and every type their public methods and properties name | [Writing an adapter](../guide/extending/writing-an-adapter.md) |

The extension API holds every type under
`NightWorksIO\MutationGate\Attribute`, `Port`, `Mutator`, `Config` and
`Extension`, but `Mutator\Engine` and `Extension\Checks`, which are the
gate's own machinery. It also holds every type a public method, a public
property or a doc block of theirs names, followed through the types those
name in turn. Most of them live under `NightWorksIO\MutationGate\Core`.

## What is internal

- Every class, interface and enum the package ships outside the extension
  API, each of which says `@internal` in its doc block. PHPStan reports a use
  of one from outside the `NightWorksIO` namespace.
- The classes of the first-party mutator sets under `plugins/`. The mutator
  names a config writes are covered, as configuration.
- The console's wording, and the HTML report's markup.
- The files under `.mutation-gate` that pass between the jobs of one run,
  such as `plan.json` and each shard's result, which one version writes for
  itself alone.
