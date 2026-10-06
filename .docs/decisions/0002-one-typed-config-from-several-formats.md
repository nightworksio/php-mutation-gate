# ADR-0002: One typed Config, read from PHP, JSON, YAML or NEON, and none needed to start

**Status:** Accepted
**Date:** 2026-09-29

## Context

The in-house gate has no config file. Its trees come from `phpunit.xml` through
a test-support class. Each floor comes from the Composer manifest nearest its
tree, under a key in `extra`. What "decides how the gate runs" is a regular
expression inside the script. That works for one repository and for nobody
else.

A public package needs a config, and it needs to start without one. Someone
trying mutation-gate on an existing project should get a useful run from
`composer require --dev` and one command. Someone adopting it for a team needs
every setting written down, reviewed and validated.

Several formats are approved rather than one:

- a PHP builder, as the canonical format;
- JSON, with a published JSON Schema;
- YAML, when `symfony/yaml` is installed;
- NEON, when `nette/neon` is installed.

All four produce one typed `Config`. Non-PHP formats name extensions by class
string. The risk of several formats is several meanings: a setting that means
one thing in PHP and another in YAML, or that one format can express and another
cannot.

## Decision

1. **One meaning, reached one way.** Each `ConfigLoader` (ADR-0001) decodes
   its file into JSON and reads it through `ConfigFile::read()`, the one
   definition of every setting, into a `Layer`: the typed sections the file
   sets, each holding only what the file writes. A preset and the command line
   are layers too. The PHP builder is no exception: its methods are typed for
   the person writing it, and underneath they write the same JSON, which the
   same definition reads. So any config converts to any other, and
   `mutation-gate config:show` prints the effective config of a project
   whatever format it is written in. Each section owns the value every setting
   in it takes when every layer leaves it out, how a config writes it and how
   the PHP builder writes it; the effective `Settings` are every layer laid
   over those values. Config is data. A closure or an object other than the
   builder's own values is refused. Code that has to run belongs in an
   extension (ADR-0001).
   - An extension's loader reads its files the same way, and
     `ConfigLoaderContract::failures()` holds it, on fixture files in its
     format, to what every loader answers: the config read into the gate's own
     layer, paths named from the file's directory, one that goes up from it
     named from the project, a date read back as `YYYY-MM-DD`, the gate's own
     problems for an invalid file and for a mutant id the format reads as a
     number, and a file that is broken or not there not judged.
   - A preset an extension registers is a layer, `withPreset(Name, Layer)`,
     which it can build with the PHP builder:
     `Gate::configure()->…->layer(ProjectRoot::origin())`, its paths named
     from the project.
   - The gate reads every layer a loader answers or a preset holds again, as
     the file it writes, through the same definition. A layer built by hand
     is judged as a file is, so a floor of 0 without a reason, a negative
     retry count or an ignore without a reason is refused however it was
     built. A preset's problems are reported at the `preset` entry that
     names it.

2. **Where the config is found.**
   - `--config=<path>`, accepted by every command, names it.
   - Otherwise the gate looks in the working directory for `mutation-gate.php`,
     `mutation-gate.json`, `mutation-gate.yaml`, `mutation-gate.yml` and
     `mutation-gate.neon`.
   - Two of them present is an error (exit code 2), not a precedence rule,
     because a second file somebody forgot is a second answer nobody reads.
   - None present is zero-config (decision 5).

   `mutation-gate init --format=php|json|yaml|neon` (default `php`) writes a
   starting file holding what should stay fixed: the detected `runner` and
   `preset`, and what its questions settled. Trees stay with the tree source,
   and the ones it found are listed as a comment (ADR-0017, which supersedes
   the snapshot of everything zero-config found). Adopting the file changes
   nothing until somebody edits it. `init` also adds `.mutation-gate/` to
   `.gitignore`, and asks only what detection cannot settle (ADR-0017). `init --ci=<provider>` also writes a CI definition, and
   `init --editor=vscode` the editor's task and recommendation, each only where
   no such file exists (ADR-0015). `init --from=<file>`, or `import <file>`,
   seeds the config from an Infection config and reports how each of its keys
   maps (ADR-0016). `mutation-gate config:show --format=php|json|yaml|neon`
   (default `json`) prints the effective config in any of the four formats.

3. **The four formats.**
   - **PHP** (canonical, and the one the documentation leads with). The file
     returns the builder:

     ```php
     <?php

     declare(strict_types=1);

     use NightWorksIO\MutationGate\Config\Floor;
     use NightWorksIO\MutationGate\Config\Gate;
     use NightWorksIO\MutationGate\Config\Ignore;
     use NightWorksIO\MutationGate\Config\Preset;
     use NightWorksIO\MutationGate\Config\Report;
     use NightWorksIO\MutationGate\Config\Runner;
     use NightWorksIO\MutationGate\Config\Tree;

     return Gate::configure()
         ->preset(Preset::laravel())
         ->runner(Runner::pest())
         ->trees(
             Tree::at('app/Domain', floor: 100),
             Tree::at('app/Http', floor: 80),
         )
         ->newCode(Floor::of(100))
         ->ignoring(
             Ignore::mutant('3f9a1c2b7d04', because: 'Both branches build the same list', until: '2027-03-31'),
         )
         ->reporting(Report::sarif('build/mutation.sarif'), Report::html('build/mutation'));
     ```

     Primitives appear only in named constructors (`Tree::at`, `Floor::of`), as
     the conventions ask of a public API (ADR-0011).
   - **JSON**. The schema ships in the package at
     `resources/mutation-gate.schema.json` and is published at
     `https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v1/resources/mutation-gate.schema.json`,
     so an editor completes and checks the file. The definition accepts the
     `$schema` key and ignores its value:

     ```json
     {
         "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
         "preset": "laravel",
         "runner": "pest",
         "trees": [
             { "path": "app/Domain", "floor": 100 },
             { "path": "app/Http", "floor": 80 }
         ],
         "newCode": { "floor": 100 },
         "ignores": {
             "entries": [
                 { "mutant": "3f9a1c2b7d04", "reason": "Both branches build the same list", "expires": "2027-03-31" }
             ]
         },
         "reports": [
             { "use": "sarif", "path": "build/mutation.sarif" },
             { "use": "html", "path": "build/mutation" }
         ]
     }
     ```

   - **YAML**, read with `symfony/yaml`, and **NEON**, read with `nette/neon`.
     - Both are in `suggest`, not `require`. Their loaders are registered only
       when the library is installed. A `mutation-gate.yaml` without
       `symfony/yaml` stops the run with exit code 2 and prints the
       `composer require --dev symfony/yaml` that fixes it.
     - **Dates need no quotes.** The YAML loader parses with
       `Yaml::PARSE_DATETIME`, and NEON reads a date as a date by itself. Each
       loader turns such a value back into the `YYYY-MM-DD` string the
       definition expects.
     - **Mutant ids are always quoted.** Both parsers read an unquoted id such
       as `12e456789012` as a number. The definition refuses a mutant id that is
       not a string, and its message says to quote it.

4. **How a setting names an adapter or an extension.** Wherever a setting
   chooses an adapter (the `runner`, the `treeSource`, the proof store
   `proofs.store`, the CI plan `ci.plan`, a `reports` entry, or a `preset`),
   the value is either a name an extension registered (`"pest"`, `"sarif"`)
   or a class string with its options:

   ```json
   {
       "extensions": ["Acme\\GateSlack\\SlackExtension"],
       "reports": [
           { "use": "Acme\\GateSlack\\SlackReporter", "with": { "channel": "#ci" } }
       ]
   }
   ```

   - The object form is `{"use": <name or class>, "with": <options>}`. A
     `reports` entry is always an object, and it also carries `path`, where a
     file report is written (ADR-0009).
   - `extensions` lists extension classes to load in addition to those found
     through Composer (ADR-0001). It is a list of class strings, empty by
     default.
   - A `use` with a backslash is a class, `Acme\Gate\SlackReporter`, or
     `\SlackReporter` for one in the global namespace. Any other is a name an
     extension registered. The choice decides this once, as it is read.
   - A class named in `use` implements the port and
     `NightWorksIO\MutationGate\Extension\Configurable`, whose one method is a
     named constructor from `Core\Config\Options`: the `with` object, read one
     key at a time as the type it should hold. `text()`, `flag()`,
     `integer()`, `number()`, `paths()` and `texts()` each answer the value,
     `NotGiven` where the key is left out, or the `Problem` at its path;
     `object()` answers the options under a key. An adapter never decodes
     JSON. The class validates its own options and returns its errors as an
     outcome, and they are reported with the same path prefix as the gate's
     own (`reports[0].with.channel`).
   - A built-in adapter's options arrive as the definition read them, with
     every default filled in, so its defaults live only in the definition. A
     CI plan and the cost model the gate builds in take theirs from `ci` and
     `costs`: `Ci::planOptions()` and `Shards::costOptions()`.
   - The schema leaves `with` open for a class string, because it cannot know a
     third party's options, and checks it strictly for every built-in name.

5. **Zero-config: what the gate assumes when no file exists.**

   | Setting | Default |
   |---------|---------|
   | Preset | `laravel` when `composer.json` requires `laravel/framework`, `symfony` when it requires `symfony/framework-bundle`, otherwise `library` (ADR-0008) |
   | Runner | Pest when `pestphp/pest-plugin-mutate` is installed, Infection when `infection/infection` is. Both installed stops the run (exit code 2) and asks the config to choose. |
   | Trees | The `phpunit` tree source: one tree per `<directory>` and `<file>` under `<source><include>` in the PHPUnit config PHPUnit itself would read (`phpunit.xml`, else `phpunit.dist.xml`, else `phpunit.xml.dist`), minus `<source><exclude>`. A `<directory>` with a wildcard is one tree per directory it matches, as PHPUnit expands it: `*`, `?` and `[...]` within a segment, `**` across any number of directories, none among them; one that matches nothing is no tree. Without a `<source>`, the preset's trees (ADR-0008); for `library` that is one tree per `autoload` path in `composer.json`, not `autoload-dev`. No tree at all is exit code 2. |
   | Test directories | The `<directory>` entries under `<testsuites>` in the same file |
   | Floors | Each tree's declared floor from the nearest manifest, and its baseline floor (ADR-0003, ADR-0005); new code at 100 |
   | Proof store | A directory, `.mutation-gate/ledger`; in the GitHub Action, that directory kept in the Actions cache (ADR-0007) |
   | CI plan | Detected from `GITHUB_ACTIONS`, `GITLAB_CI`, `BUILDKITE` or `CIRCLECI`, otherwise JSON (ADR-0006) |
   | Reports | The console, plus what the environment offers: annotations and the step summary under GitHub Actions, and the sticky PR comment on a pull request with a token (ADR-0009) |

   Settings resolve in this order, later winning: zero-config defaults, then
   presets (`preset` takes one name or a list, applied in order), then the
   config file, then command-line options.
   - Layers are laid section by section. A setting a later layer writes
     replaces the earlier one's, and a setting it leaves out keeps it.
   - Maps merge by key: a later layer's `costs.secondsPerLine` prefixes lie
     beside an earlier one's. `badge.colors` is the exception: its colours are
     bands of one scale, so a layer that sets them replaces them whole.
   - Lists concatenate, and an entry equal to an earlier one is dropped.
   - The layer an entry comes from can decide what it means. A mutator set
     only presets turn on is skipped with a warning where nothing registers
     it, and the same set named in the config file or on the command line is
     exit code 2 (ADR-0021, decision 12).
   - `trees` is the exception: a layer that sets it replaces the list whole, so
     declaring trees never adds them to the ones `phpunit.xml` or a preset
     found.
   - An adapter a later layer chooses replaces the earlier one with its
     options, and what the runner withholds only grows (ADR-0004).
     `shards.seconds` and `shards.target` replace each other (ADR-0013).
   - The command-line options that set config are `--runner=<name>`,
     `--report=<name>:<path>` (repeatable, adding to `reports`),
     `--budget=<duration>` (ADR-0008) and `--ci=<name>` (ADR-0006).

6. **Validation reports everything at once, by path.** Each layer is read on
   its own, and what only every layer together can say, that each preset a
   layer names is registered, that a runner is chosen and that no ignore
   outlasts `ignores.maxDays`, is judged once every layer reads. A layer's
   own problems come first: a preset is looked up in the registry the
   config file's `extensions` extend, so it is judged once that file reads. A config with three
   mistakes prints three errors, each with its path and what was expected:
   `trees[1].floor: expected a number from 0 to 100, got "80"`. Types are
   strict, so a string is not a number. An unknown key is an error and suggests
   the nearest known one (`newcode` → `newCode`), because a misspelt key that is
   silently ignored is a setting that silently does nothing. Durations are
   written `90s`, `15m` or `1h30m`, and written back in the largest units
   that hold them: `90s` is `1m30s`. Dates are `YYYY-MM-DD`. Paths are
   relative to the config file, or to the working directory when there is
   none, and a config the gate writes, such as `init`'s, names them from its
   own directory. So are globs, the `phpunit` tree source's `fallback`, the
   `directory` store's `path` and each `costs.secondsPerLine` prefix but
   `""`, which is every path wherever it is written. A path or a glob that
   lands outside the project, up through `..` or absolute, is refused
   (`expected a path inside the project`). A config file outside the
   project names its paths the same way, so it names only those that land
   inside the project: `../project/src`. Only the command line names a file
   outside the project, by its absolute path, as `--report` does. The
   configuration reference lists every key with its type, its default and the
   ADR that decides it. It is generated from the same definitions as the
   schema, into `.docs/reference/configuration.md`, and the README holds it
   until that page is built (ADR-0018).

7. **The schema is generated, not written.** `mutation-gate config:schema`
   prints JSON Schema (draft 2020-12) from the same definition every layer is
   read through. The committed `resources/mutation-gate.schema.json` must equal that
   output, and a test fails when the two differ.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **PHP only** | Simplest, and it is what Rector and Pint's PHP configs do. The approved scope has more, and JSON with a schema is what editors and CI templates can read, check and write without running PHP. |
| **Each format with its own loader producing `Config` directly** | Four implementations of every rule, and a setting drifts in one of them. One JSON form and one definition make the formats equivalent by construction. |
| **A hand-written JSON Schema as the source of truth** | The schema cannot express every rule (a tree path that exists, an expiry within `ignores.maxDays`), so a reader would still be needed, and the two would drift. Generating the schema from the definition every layer is read through keeps one source. |
| **`mutation.php` (and `mutation.json`, …)** | A generic name a project may already use for something else, as the in-house gate's own script does. `mutation-gate.*` matches the package and the binary, and cannot be mistaken for another tool's file. |
| **Precedence when several config files exist** (as `phpunit.xml` over `phpunit.xml.dist`) | Invites a forgotten local file that silently wins. The gate is a CI decision, and one file is one answer. |
| **Silently ignoring unknown keys** | The most common config mistake is a typo, and ignoring it makes a stricter setting quietly not apply. |
| **Floors only in each package's `composer.json` `extra`** (the in-house gate's way) | Kept as a tree source for monorepos (ADR-0005), not as the only way: a single-package project should not need to edit `composer.json` to set a floor, and the baseline file carries floors that rise (ADR-0003). |
| **Callables in the PHP config** (a closure deciding what reaches what) | Cannot be converted to JSON, cannot be part of the proof key (ADR-0007) and cannot be shown by `config:show`. An extension is the place for code. |

## Consequences

**A first run needs no file.** `vendor/bin/mutation-gate` in a project with a
`phpunit.xml` `<source>` and Pest installed plans, runs and reports.

**Every format can say everything.** A setting added to the builder is in the
JSON, the definition and the generated schema at once, and the test that compares
the committed schema fails until it is regenerated.

**YAML and NEON cost nothing unless used.** The package's `require` stays small.

**Extension authors validate their own options.** Their errors read like the
gate's own.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the ConfigLoader port and extension discovery
- [ADR-0003](0003-a-floor-only-rises.md): the baseline that zero-config floors come from
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): monorepo tree sources
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): presets
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): `init --ci` and `init --editor`
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): `init --from`, and webhook URLs kept out of the config
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): what `init` writes and asks, and the PHPUnit configs zero-config reads
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the generated configuration reference in `.docs/reference/`
