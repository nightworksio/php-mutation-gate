# Architecture Decision Records

An ADR records a decision where a reasonable engineer could have chosen
otherwise. It gives the alternatives and why they lost, so that revisiting the
decision starts from evidence rather than from scratch. Together with the
[README](../../README.md), the ADRs are the whole of this package's design.
There is no separate requirements document, and there are no requirement IDs.

## Rules

1. **An ADR is immutable once Accepted.** To change a decision, write a new
   ADR that supersedes the old one, and link the two both ways.
2. **Numbers are permanent and never reused.**
3. **A commit that implements a decision names it** in a `Spec:` trailer, for
   example `Spec: 0006` ([ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md)).

## Index

| # | Decision | Status |
|---|----------|--------|
| [0001](0001-a-framework-free-core-behind-eight-ports.md) | A framework-free core behind eight ports, with adapters found through Composer | Accepted |
| [0002](0002-one-typed-config-from-several-formats.md) | One typed Config, read from PHP, JSON, YAML or NEON, and none needed to start | Accepted |
| [0003](0003-a-floor-only-rises.md) | A tree's floor only rises, is committed beside the code, and new code has a floor of its own | Accepted |
| [0004](0004-pest-and-infection-behind-one-runner-port.md) | Pest and Infection behind one Runner port, each reporting every mutant | Accepted |
| [0005](0005-what-a-change-reaches-is-what-is-mutated.md) | What a change reaches is what is mutated, package by package, judged by the tests that hold it | Accepted |
| [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md) | Shards are cut by learned cost, planned once, and rendered for any CI | Accepted |
| [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) | A proof is keyed by everything its result could depend on, so a skip is never wrong | Accepted |
| [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md) | A run spends its time on the riskiest code first, and never passes what it did not judge | Accepted |
| [0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) | Every verdict is readable by a machine, a reviewer and a badge, and every survivor says how to reproduce it and what the tests miss | Accepted |
| [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md) | The gate runs while you work and before you push, with the same verdict CI gives | Accepted |
| [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md) | The package holds itself to the gate it ships, and to the standards of the in-house project | Accepted |
| [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) | A run learns which tests kill and how wide to cut, proves equivalent survivors, and lets a fork read what the default branch proved | Accepted |
| [0014](0014-every-test-is-judged-by-what-it-kills.md) | Every test is judged by what it kills, the kill matrix is exported, and any mutant can be explained | Accepted |
| [0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) | A survivor reaches the editor, the test file and the commit, and `init` writes the CI that runs the gate | Accepted |
