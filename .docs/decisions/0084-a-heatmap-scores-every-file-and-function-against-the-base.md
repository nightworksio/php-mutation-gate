# ADR-0084: A heatmap scores every file and function against the base

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof holds beside its mutants; [0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the JSON report, the HTML report and the pull request comment show; [0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): what `summary.json` and the dashboard show

## Context

A verdict scores each tree, and its change against the base where a
pre-commit check compares it. Inside a tree, a reader sees the survivors
and the files they are in, but not which files and functions score lowest,
nor which of them a change brought down. A ledger keeps each unit's newest
proof, so the default branch already holds what each file scored; it does
not hold which function each mutant was in, and a killed mutant is kept as
four fields that leave no room for one.

## Decision

1. **A heatmap scores every file a verdict judged, and each function that
   holds its mutants.** A file's score is its mutants counted as its tree
   counts them. A mutant is in the narrowest named function or method of its
   file whose lines, from its `function` keyword to its closing brace, hold
   its line; a closure's line is its function's, and a mutant outside every
   function is in its file's score alone. A method is named `Class::method`
   by its class's short name. Files and functions are listed lowest first, a
   file with nothing to mutate last.

2. **Each is compared with the base.** The base is the default branch's
   newest proof of each unit the verdict judged, judged as the verdict
   judges. A unit the default branch never proved has no change.

3. **Each proof names the functions that hold its mutants.** A proof gains
   an optional `functions` field: `[name, first line, last line]` for each
   function of its unit's file, as the file read when the proof was
   recorded, that holds one of its mutants. A base mutant is in the
   function its proof names for its line. Reading keeps each well-formed
   span and drops the rest; a proof without the field gives its functions
   no change, so the ledger's format stays 4.

4. **Where it shows.**
   - The JSON report gains an optional `heatmap`: every file, each with its
     `score`, `base`, `notKilled` and `functions`.
   - The HTML report shows it as one folded table, each file's functions
     folded under it.
   - `summary.json` gains an optional `heatmap`: the lowest-scoring files, as
     many as `heatmap.files` says, 10 by default. The dashboard shows them
     collapsed under each repository's row, and `dashboard.json` carries them
     as `lowestFiles`. Neither format changes.
   - The pull request comment shows, after the trees, the files the change
     reaches whose score fell most, as many as `heatmap.comment` says, 5 by
     default, then any other it reaches below its tree's floor, each with its
     three lowest-scoring functions inline. A file the change reaches holds a
     mutant on a changed line. `heatmap.comment: 0` leaves the section out.
   - `heatmap.methods: false` scores files only.

## Alternatives considered

- **A function's name on each mutant record.** A killed mutant is kept as
  `[id, line, mutator, killers]`, and a reader refuses any other length, so
  a fifth field would make an older gate refuse a newer ledger. One list of
  spans for each proof says the same in fewer bytes.
- **Reading the base's source through git.** It needs history in every CI
  checkout, and the proof can be of older content than the branch's head, so
  lines would land in the wrong function.
- **Scores by file only.** A file's score hides the one method that lets
  mutants through.

## Consequences

**A reader sees where a project is weak and what a change weakened.** The
comment names the files a pull request brought down, the report every file
and function, and the dashboard the weakest files of each repository.

**Functions trend from the next default-branch run.** A proof recorded
before this has no spans, so its functions show no change until the
default branch records its unit again.

**The verdict judges the base's proofs once more.** It reads no file and
runs no test for them; reading each scored file's functions reads every
file the verdict judged once, unless `heatmap.methods` is off.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): proofs
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the reports
- [ADR-0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): `summary.json` and the dashboard
