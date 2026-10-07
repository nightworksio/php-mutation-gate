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
