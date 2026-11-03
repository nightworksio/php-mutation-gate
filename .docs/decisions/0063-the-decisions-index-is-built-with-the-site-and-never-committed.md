# ADR-0063: The decisions index is built with the site and never committed

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0038](0038-the-documentation-is-a-versioned-site-built-from-docs-where-it-stands.md): its pages rendered as they stand, for the decisions index

## Context

The index of the decisions lists each ADR's number, title and status, and
what later ADRs change in it. `decisions_index.py` renders it from the ADR
files: each one's heading, its `**Status:**` line and the change lines a later
ADR carries. A committed copy in `.docs/decisions/README.md` makes every pull
request that adds an ADR, or changes what an earlier one says, edit the same
lines of one file. Two such pull requests open at once conflict there, and the
second is rebased and re-run for a table that a script writes. Two pull
requests that each take the next free number also each pass alone, and only
the second to merge finds the number taken, if anyone looks.

ADR-0038 renders `.docs` as it stands, page for page, and its remark plugins
already rewrite what a page says on the site: its title and its links.

## Decision

1. **The site builds the index.** A remark plugin runs
   `decisions_index.py rows`, which prints each ADR's row as JSON, and puts
   the table between the README's `<!-- index:start -->` and
   `<!-- index:end -->` markers on the site's copy of the page. The links in
   it are rewritten like every other link on a page.
2. **The README commits only the markers**, and a line that points a reader
   on GitHub at the site's copy of the page.
3. **`decisions_index.py validate` fails, saying why**, where an ADR file
   cannot be read as one, where two files take one number, or where anything
   stands between the markers. `rows` refuses on the same problems, so the
   site never builds an index from them. The markdown job of hygiene runs
   `validate`, and the scripts job runs it against the repository through its
   test.
4. **A change to `decisions_index.py` builds the site**: `scope` lists it as
   an input of the `site` job, and `pages` deploys on it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Commit the index, with a test that fails when it is stale** | Every pull request that adds an ADR edits the same lines, so parallel ones conflict on a table a script writes. |
| **A bot that writes the index after each merge** | `main` takes changes only through a pull request, so every ADR would bring a second pull request and its CI, and `main` would hold a stale index until it merged. |
| **Commit only the most recent decisions** | Each new ADR still edits the same lines, so it conflicts the same way. |
| **No check on numbers** | Two pull requests that take one number each pass alone, and nothing says so once both merge. |

## Consequences

- A pull request that adds an ADR touches no shared line of the README, so
  parallel ADRs conflict only where they take one number, and `validate`
  says which.
- On GitHub, the README shows no table, only the link to the site's index.
- Building the site needs `python3`, which the runner images carry.

## Related

- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the documentation, versioned and tested with the code
- [ADR-0038](0038-the-documentation-is-a-versioned-site-built-from-docs-where-it-stands.md): the site this amends
- [ADR-0055](0055-each-gated-job-runs-for-its-own-inputs-and-each-runner-leg-for-its-own-test-support.md): the job inputs `scope` gives `site`
