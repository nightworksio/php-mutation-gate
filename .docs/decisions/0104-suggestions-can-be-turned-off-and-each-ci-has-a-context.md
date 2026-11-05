# ADR-0104: Suggestions can be turned off, and each CI has a context

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0071](0071-a-config-holds-a-block-for-each-context-a-run-is-in.md): which contexts a run is in; [0076](0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md): when `config:suggest` proposes changes

## Context

The gate suggests changes in several places: `config:suggest`'s proposals,
what stopping a doomed pull request's run would have saved (ADR-0103), and
the files most of the suite runs through that nothing holds. A team that has
weighed a suggestion sees it on every run. And a setting that should differ
between the CIs a project runs in, such as one CI's runners being slower,
could only differ by local, CI, pull request and default branch.

## Decision

1. **`suggest.enabled` turns every suggestion on or off**, on by default.
   Off, `config:suggest` reads no ledger and says suggestions are off, the
   verdict warns of no saving a doomed run missed, and it names no file that
   nothing holds. Problems, failures and the warnings that are no
   suggestion stay as they are. `doctor` and `init` say what they find
   whatever it is, as a reader asked them.

2. **Each CI has a context block:** `github`, `gitlab`, `bitbucket`,
   `azure`, `buildkite`, `jenkins` and `circleci`. A run in CI lays `ci`,
   then the block of the CI its plan is for, whether the config names the
   plan or the gate detects it, then `pr` or `main`. A run whose plan is no
   CI's own, such as `json`, lays no CI's block. `--context` takes each,
   laid over `ci`. The PHP builder writes them with `Context::gitHub()`,
   `gitLab()`, `bitbucket()`, `azure()`, `buildkite()`, `jenkins()` and
   `circleCi()`.

3. **So one CI's suggestions are turned off with its block**, as
   `contexts.gitlab.suggest.enabled: false`, and any other key can differ by
   CI the same way.

## Alternatives considered

- **A `suggestions` section beside `suggest`.** Two sections named alike
  would split one subject.
- **A map of environments under `suggest` alone.** Each setting that needs
  to differ by CI would need its own map; one context per CI serves them all
  with the precedence ADR-0071 already gives.

## Consequences

**A team can silence what it has weighed** without losing what fails or
warns of a problem.

**A setting can differ by CI.** The CI's block sits under `pr` and `main`,
so what a pull request's run does still wins over what a CI does.

## Related

- [ADR-0071](0071-a-config-holds-a-block-for-each-context-a-run-is-in.md): context blocks
- [ADR-0076](0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md): `config:suggest`
- [ADR-0103](0103-a-doomed-shard-fails-fast.md): what a doomed run would have saved
