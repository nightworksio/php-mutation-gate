# ADR-0054: A pull request runs the gates its change can move, and reads its workflows job by job

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its `what changed` job, for what it skips

## Context

ADR-0011's `what changed` job skips the PHP jobs for a documentation-only
change. `.github/scripts/scope.py` decides it from the paths a pull request
changed, and gives four outputs: `code`, which starts every PHP gate;
`runners`, the runner contract's legs; `warm`, the warm workers' job; and
`site`, the docs site's build. Its rules lived in the script alone.

Any change to `ci.yml` or to `end-to-end.yml`, the workflow it calls, counted
as code, as a change every runner shares. So the pull request that removed
the `macos` job (ADR-0053), whose other changes were documentation, ran every
PHP gate, the mutation run included, though no job it changed is a gate.

## Decision

1. **A pull request is narrowed by its paths, and a push, a schedule or a run
   with no base runs every gate**, so a break a narrowed run missed shows on
   `main` within one merge (ADR-0019). The rules for the paths are
   `scope.py`'s: documentation starts no PHP gate, except the documents the
   suites read; a script under `.github/scripts` is code only where a PHP
   gate job runs it; a runner's legs run where its adapter, its fixture or
   what every runner shares changed; the warm workers run where the PHPUnit
   runner, its worker or what every runner shares changed; the site builds
   where the docs, the site or what prints a link changed.
2. **`ci.yml` and `end-to-end.yml` are read job by job**, at the base and at
   the head, each without comment lines, blank lines or trailing space.
   - A change to what every job shares, the keys outside `jobs` (`on`, `env`,
     `permissions`, `concurrency`, `defaults`), or to the `scope` job, runs
     every gate.
   - A job of `ci.yml` added or changed runs the gates of the outputs its
     text reads. A job no output gates, such as `evidence`, whose `needs`
     list changes with every job added or removed, runs nothing.
   - A job of `end-to-end.yml` added or changed runs the PHP gates, and the
     warm workers too where it is the job that reads `warm`.
   - A removed job runs nothing.
   - Where the base cannot be read, a change to either workflow runs every
     gate.

This amends ADR-0011's `what changed` job: it skips what a change cannot
move, of which a documentation-only change is one case.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep every workflow change as code** | A pull request that removes a job runs the mutation gate, which no job it changed reaches. |
| **Parse the workflows as YAML** | The scripts take no dependency beyond Python's own library, and the jobs' text, at the indentation GitHub reads, is what a change moves. |
| **One output per job** | Every job's `if:` would read its own output, and adding a job would mean adding an output too. The four outputs group the jobs that share their inputs. |

## Consequences

- A pull request that edits a job no gate reads, or removes one, runs no PHP
  gate. One that edits one PHP gate's job runs every PHP gate, since `code`
  starts them all.
- The push to `main` that merges such a pull request runs every gate.

## Related

- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the CI jobs, which this amends
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): every gate on a push, a schedule and a run with no base
- [ADR-0053](0053-ci-runs-the-suite-on-linux-alone-and-macos-is-best-effort.md): the job whose removal showed the gap
