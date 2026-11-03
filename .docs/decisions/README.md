# Architecture Decision Records

An ADR records a decision where a reasonable engineer could have chosen
otherwise. It gives the alternatives and why they lost, so that revisiting the
decision starts from evidence rather than from scratch. Together with the
[README](../../README.md), whose reference tables ADR-0018 moves to
`.docs/reference/`, the ADRs are the whole of this package's design.
There is no separate requirements document, and there are no requirement IDs.

## Rules

1. **An ADR is immutable once Accepted.** To change a decision, write a new
   ADR that supersedes the old one, and link the two both ways.
2. **Numbers are permanent and never reused.** No ADR holds 0012.
3. **A commit that implements a decision names it** in a `Spec:` trailer, for
   example `Spec: 0006` ([ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md)).
4. **What an ADR changes in an earlier one is said in its own header**, one
   line under its Status and Date for each change, for example
   `**Amends:** [0003](0003-a-floor-only-rises.md): its lowering and truncated floors`,
   with Supersedes, Amends, Narrows or Grows, and one that holds only in part
   ends in `, for <which part>`. An ADR's change lines are written with it and
   never added later.
5. **The index is built, never committed** ([ADR-0063](0063-the-decisions-index-is-built-with-the-site-and-never-committed.md)).
   The docs site renders it below from the ADRs' headings, statuses and change
   lines when it is built. `python3 .github/scripts/decisions_index.py validate`
   fails where an ADR cannot be read as one, where two files take one number,
   or where anything stands between the markers below, and CI runs it.

## Index

On GitHub, the [docs site's copy of this page](https://nightworksio.github.io/php-mutation-gate/decisions/)
lists every decision with its status.

<!-- index:start -->
<!-- index:end -->
