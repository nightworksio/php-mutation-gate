# ADR-0083: Doctor fixes what it found, after a diff

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): what `doctor` may write

## Context

`doctor` names what would fail or slow a run, and for each finding prints
the line to add or the command to run. Many of those fixes are edits to a
file the finding already names: a setting in php.ini, an element of the
PHPUnit config, a line of `.gitignore`, a patch to a vendored package. A
person copies each into the right file by hand.

A CI definition that `init --ci` wrote pins the release of the gate it
runs. Once the project installs a newer release, CI keeps running the old
one, and nothing says so.

## Decision

1. **`doctor --fix` applies the fixes it has a repair for.** It runs the
   same checks, prints the same report, then prints the unified diff of
   every file it would change, and for each finding it cannot fix, what to
   do in its place. It asks once, "Apply N changes to M files? [y/N]".
   `--yes` answers yes. With no terminal to ask and no `--yes`, it writes
   nothing and says how to apply the changes. Once it wrote, it checks
   again, prints that report, and exits as `doctor` does. `--fix` writes
   text, so it refuses `--format=json`.

2. **The repairs.**
   - `opcache-on-the-command-line`: `opcache.enable_cli=0` and
     `opcache.file_cache=` in the php.ini the runner's PHP loads, each on
     the last line that sets it, or added at the end.
   - `no-coverage-driver`: `extension=pcov` in that php.ini, where the PHP
     offers pcov. Where it does not, the repair names `pecl install pcov`
     and runs nothing.
   - A php.ini the PHP does not load, or that cannot be written, is named
     and left alone.
   - `memory-cap-lifted`: the PHPUnit config's `<ini name="memory_limit">`
     set to `runner.memory`.
   - `coverage-empty`: each tree added to the PHPUnit config's `<source>`,
     adding the `<include>` or the `<source>` where it has none. Both
     PHPUnit edits change the XML as text, so every line they leave alone
     keeps its formatting.
   - `workspace-not-ignored`: `.mutation-gate/` added to `.gitignore`.
   - `pest-unpatched` and `infection-unpatched`: the files `pest:patch` and
     `infection:patch` would write, as they would write them.
   - `workflow-pin-stale`: see 3.

   Changes to one file apply in turn, each from the text the one before
   left. A file that changed after `doctor` read it is left as it is.

3. **`workflow-pin-stale` is advice.** A CI definition that runs the gate
   and pins another release than the installed one is found: a `uses:` of
   `nightworksio/php-mutation-gate`, its action or its reusable workflow,
   at another full commit, or the gate's image at another tag. Its repair
   pins the installed commit, with the version in a comment where the
   release has one, and the image at the release's tag. A branch Composer
   installed has no image tag, so its images are left alone. A gate whose
   release is not known pins nothing.

## Alternatives considered

- **Leaving system files alone.** opcache and the coverage driver live in
  the runner PHP's php.ini, outside the project. Printing the change
  instead keeps the copying by hand that `--fix` exists to remove, and the
  diff shows exactly what changes before anything is written.
- **Running `pecl install`.** Installing an extension needs privileges and
  a build toolchain the gate cannot vouch for; naming the command keeps
  the change in the person's hands.
- **Writing without asking.** A fix edits files outside the gate's own
  workspace; one question, or `--yes`, keeps that a choice.

## Consequences

**Most of what `doctor` finds is one command away.** A person reads the
diff and answers once.

**A php.ini a scanned file overrides keeps its finding.** A setting in a
file of the PHP's scan directory wins over php.ini. Checking again after
writing shows the finding still there, and the person knows where to look.

## Related

- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): `doctor`
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the pin a CI definition holds
