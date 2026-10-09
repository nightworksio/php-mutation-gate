# Pre-push hook and watch mode

The gate can judge your change before CI does: on every save, before every
push, and as a score change at every commit.

```sh
vendor/bin/mutation-gate watch          # re-judges what each save reaches, within 60 seconds
vendor/bin/mutation-gate hook install   # a pre-push hook: judges the pushed commits within 5 minutes
vendor/bin/mutation-gate hook install --pre-commit   # also shows the score change at each commit
```

The time limits are `local.watchBudget` and `local.prePushBudget` in the
[config](../../reference/configuration.md#every-key). The pre-commit hook runs
nothing and never blocks a commit: it prints each reached tree's score change
from the local ledger. To see survivors in your editor, see
[editors](editors.md).

## Hook managers

A project that runs its hooks through CaptainHook, GrumPHP or the
pre-commit framework calls the gate from that manager's config instead:

```sh
vendor/bin/mutation-gate init --hook=captainhook   # captainhook.json: pre-push and pre-commit actions
vendor/bin/mutation-gate init --hook=grumphp       # grumphp.yml: a shell task running pre-commit
vendor/bin/mutation-gate init --hook=pre-commit    # .pre-commit-config.yaml: this repository's hooks
```

`init` writes the file where the manager reads none, and otherwise prints
what to add to it, then the command that installs the manager's hooks.
`--stdout` prints the file instead.

- **CaptainHook** hands git's lines to the gate as
  `pre-push --stdin={$STDIN}`.
- **GrumPHP** runs only `pre-commit`, which never blocks, and has no
  pre-push hook: `vendor/bin/mutation-gate hook install` adds the gate's
  beside it.
- **The pre-commit framework** takes the hooks `mutation-gate-pre-push` and
  `mutation-gate-pre-commit` from this repository's
  `.pre-commit-hooks.yaml`, pinned to the gate's commit, and needs
  pre-commit 4.4.0 or later. Each runs `vendor/bin/mutation-gate` from the
  top of the working tree; where Composer links it elsewhere, `init` sets
  each hook's `entry`. The framework hands the pre-push hook the first ref
  that sends commits.

## What a budgeted run counts

A run under a budget, as `watch` and `pre-push` are, counts each unit it
never started by its newest result where that result still stands for the
code: its survivors always, and a kill proved at an earlier commit where
nothing that changed since reaches the unit, or a test that killed it, by a
name the code uses. For a kill by static analysis, the file the analyser's
finding sits in takes the place of the tests. A file that is not PHP, or a
PHP file that runs code when it is loaded, is named by the words of its file
name. A change to a file that decides how the gate runs, such as
`composer.json`, `composer.lock` or the gate's config, or to a file
`autoload.files` lists, carries no kill. Following names misses a class
wired only in YAML or XML service definitions, or that a container builds
for a key other than its name, a name built by concatenation, a call through
`__call` onto a class nothing names, a file read by a path built from pieces
or found by listing a directory; a full run judges those again. In CI, a
kill carries only where the clone holds the commit it was proved at, which
`fetch-depth: 0` makes sure of.
