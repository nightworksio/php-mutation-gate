# ADR-0046: The Stryker report is a reporter of its own

**Status:** Accepted
**Date:** 2026-11-01

## Context

- **Where the report is written.** The `html` reporter writes
  `mutation-report.json`, the verdict in the open
  `mutation-testing-report-schema` format, beside `index.html` in its
  directory (ADR-0009). No other reporter writes it.
- **Who reads the format.** Stryker's dashboard takes an upload of that
  file. So do the `mutation-testing-elements` viewer and any tool that reads
  the schema.
- **What a project does today.** To get the file alone, it writes the whole
  HTML report and picks one file out of its directory.

## Decision

This amends ADR-0009's decision 1, which lists the built-in file reports.

1. **`stryker` is a built-in file report.** Its entry names a `path`, a
   file, and it writes there the same `mutation-report.json` the `html`
   reporter writes: every mutant with the Stryker status ADR-0009 maps it
   to, and the source of every mutated file.
2. **The `html` reporter keeps writing `mutation-report.json`** beside
   `index.html`, so an HTML report stays one directory a viewer can open.
3. **The PHP config builder has `Report::stryker($path)`**, as it has a
   method for each built-in file report it names.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **An upload to Stryker's dashboard built in** | An outside service with its own account and key, which ADR-0009 leaves to an extension. The file is what an upload step sends. |
| **A `with` option on `html` that writes the JSON alone** | One reporter that writes two kinds of output, chosen by an option, where a name says it. |
| **`mutation-report.json` moved out of the `html` directory** | A directory a viewer opens would lose the file it reads. |

## Consequences

**A project gets the Stryker report with one entry,** and uploads it to
the dashboard, or opens it in a viewer, with no HTML report written.

## Related

- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the file reports and the Stryker statuses, which this amends
