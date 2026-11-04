# ADR-0070: A config holds a block for each context a run is in

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0002](0002-one-typed-config-from-several-formats.md): its rule 5, the order layers resolve in, and its rule 2, what `config:show` prints

## Context

A project runs the gate in more than one place: on a developer's machine
through watch, pre-commit and pre-push, on every pull request, and on the
default branch. Each place wants different settings. A pull request wants a
tight budget and few shards, the default branch wants every shard it can
get, and a developer's machine wants a short budget and no sharding at all.
ADR-0002 gives one config whose layers are the presets, the config file and
the command line. None of them depends on where the run is, so a project
that wants different settings has to pass command-line options in each CI
job, or keep a second config file and name it with `--config`. ADR-0002
refuses two config files for exactly this reason.

Presets (ADR-0008) are named layers chosen by the kind of project, such as
`laravel` or `library`. They hold what a kind of project needs wherever it
runs. Where a run happens is a different question, and a preset cannot
answer it.

## Decision

1. **`contexts` holds a block for each context a run can be in.** Its keys
   are `local`, `ci`, `pr` and `main`, and each block holds the keys of a
   layer:

   ```json
   {
       "budget": "30m",
       "contexts": {
           "local": { "budget": "2m" },
           "ci": { "shards": { "max": 8 } },
           "pr": { "budget": "10m", "shards": { "max": 4 } },
           "main": { "shards": { "max": 16 } }
       }
   }
   ```

   The PHP builder writes a block with `Context::local()`, `Context::ci()`,
   `Context::pullRequest()` and `Context::main()`, each taking a
   `Gate::configure()` of its own, in `with()`. A block that is not an
   object, a context with any other name and `contexts` inside a block are
   refused, and an unknown context's problem names the nearest one.

2. **A block refuses what is read before the context is known.** `preset`
   and `extensions` decide which layers and adapters exist before any
   context is told, and `ci.plan` and `ci.defaultBranch` decide the context
   itself. A block that sets one of them is exit code 2, with the problem at
   its path, such as `contexts.pr.ci.plan`. Every other key a layer may write
   is allowed.

3. **The context is told from the environment and the CI plan.**
   - Outside CI (`CI` and `TF_BUILD` unset), a run is in `local`.
   - In CI, a run is in `ci`. It is also in `pr`, laid over `ci`, where the
     CI plan says the run is of a pull request or a merge group, and in
     `main`, laid over `ci`, where the run is on the default branch:
     `ci.defaultBranch`, else the one the CI names, else `main`. A push to
     any other branch, and a run the CI plan says nothing of, are in `ci`
     alone.
   - The CI plan is the one the settings choose without any block, which no
     block can change, by rule 2. Telling the context is a second pass over
     the same layers.
   - watch, pre-commit and pre-push are in `local` wherever they run.
   - `--context=<local|ci|pr|main>`, which every command takes, puts the run
     in that context and in those it is laid over: `--context=pr` is `ci`,
     then `pr`. It beats what the environment says, and it beats the `local`
     that watch, pre-commit and pre-push take. Any other value is exit code 2.

4. **Each layer resolves its blocks before the layers are laid.** A layer's
   blocks for the run's contexts are laid over the rest of that layer, in
   order, and the block is gone from the result. The layers are then laid as
   ADR-0002 lays them: zero-config defaults, the presets in order, the config
   file, the command line. So a preset (ADR-0008) can carry blocks of its
   own, and a setting the config file writes at its top still beats a
   preset's block, as a later layer beats an earlier one. A block replaces,
   merges and concatenates as a layer does: a block's `trees` replace the
   layer's, and its `reach.everything` adds to them.

5. **The settings a run judges by are the resolved ones.** The proof key
   (ADR-0007), what a change to the config file is compared by (ADR-0005)
   and every adapter read the settings with the run's blocks laid and none
   left. A block that changes a setting that affects results therefore keys
   that context's proofs apart from another context's. A block that changes
   only what judges or reports keys nothing apart.

6. **`config:show` prints the config as written, and `--resolved` prints a
   run's.** `config:show` prints the effective config with every layer's
   blocks kept, laid context by context, so it still converts to every
   format. `config:show --resolved` lays the blocks of the contexts the run
   is in, or of those `--context` names, prints the result without
   `contexts`, and names the contexts on standard error:
   `Resolved for the contexts ci, pr.`

7. **The schema names a block's keys once.** The generated schema
   (ADR-0002, rule 7) defines a block under `$defs.contextBlock`, with the
   keys rule 2 refuses marked `not`, and each context under `contexts`
   refers to it.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Top-level `local`, `pr`, `main` and `ci` keys** | `local` (ADR-0010) and `ci` (ADR-0006) are already sections, so the names clash, and moving them needs a migration (ADR-0026). One `contexts` key clashes with nothing and is one entry in the schema. |
| **Merge every layer's top first, then every layer's blocks** | A preset's `pr` block would beat the config file's own top-level setting, so a project could not override a preset without writing the same block itself. Resolving each layer first keeps "a later layer wins" true. |
| **A block that only names presets** (`contexts.pr: "fast"`) | Reuses presets, but needs a registered preset for every tweak, and a project cannot write a one-off value in its own file. |
| **Allow every key in a block, and use the first pass's value for the ones that decide the context** | A block that sets `ci.plan` would be silently ignored, which ADR-0002 refuses for an unknown key for the same reason. |
| **A second config file per context** | ADR-0002 refuses two config files, because the forgotten one becomes a second answer nobody reads. |
| **Contexts told only from environment variables** | Every CI spells "pull request" and "default branch" differently, and the CI plans (ADR-0006) already read them. Asking the plan keeps one reading. |

## Consequences

**One file holds every place's settings.** A CI job needs no options to get
its context's settings, and `config:show --resolved --context=pr` shows what
a pull request runs with on any machine.

**A context's proofs are its own where its settings differ in what affects
results.** A block that sets only what judges or reports, such as
`shards.max` or `budget`, keys nothing apart, so a pull request still
carries the default branch's proofs. A block that sets `timeouts.seconds`
keys them apart.

**Telling the context costs one more pass over the layers.** It builds the
CI plan, which reads only the environment and the event file.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): the layers, the definition, the schema and `config:show`
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change to the config file reaches
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the CI plans that say what a run is on
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proof key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): presets
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): watch, pre-commit and pre-push
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): a merge group, judged like a pull request
