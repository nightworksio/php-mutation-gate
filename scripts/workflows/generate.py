#!/usr/bin/env python3
"""The reusable workflow, written from its template with every download retried once (ADR-0072).

The template is the parts in `TEMPLATE`, by what each holds: the trigger,
with the workflow's inputs and secrets, then the jobs that plan, those that
run the shards, and those that judge and deliver, in that order.

Usage:
  python3 scripts/workflows/generate.py           write .github/workflows/mutation-gate.yml
  python3 scripts/workflows/generate.py --check   exit 1 where the written file is not what the template gives

Each `actions/download-artifact` step of the template becomes two: the step
itself, with an id of its own, the minutes the `download-timeout` input gives,
two by default, and `continue-on-error: true`, and
a step named `Retry downloading <what it downloads>` that downloads the same
artifact to the same path where the first one's outcome is `failure`. A
download that may find nothing, one whose template step carries
`continue-on-error: true`, keeps it on the retry. `DOWNLOADS` names each artifact by how its name
or pattern starts, a longer start before a shorter one it begins with, and
gives the step's id and the retry's words. A download of an artifact it does
not name stops the generator, so every retry says what it fetches.

Two halves: `generated` decides from the template's text, and `main` reads
and writes the files.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = Path("scripts/workflows/mutation-gate")
PARTS = ("trigger.yml", "planning.yml", "shards.yml", "verdict.yml")
WORKFLOW = Path(".github/workflows/mutation-gate.yml")
RECIPE = "python3 scripts/workflows/generate.py"

DOWNLOAD = "      - uses: actions/download-artifact@"
MINUTES = "${{ inputs.download-timeout }}"

DOWNLOADS = (
    ("mutation-gate-fetched-", "fetched", "the store's copy of the ledger"),
    ("${{ inputs.coverage }}", "map", "the coverage map"),
    ("mutation-gate-delivery-plan-", "delivery", "the plan's delivery"),
    ("mutation-gate-delivery-survivors-", "delivery", "the survivors' delivery"),
    ("mutation-gate-survivors-", "shares", "what the survivors' shares made"),
    ("mutation-gate-delivery-", "delivery", "the verdict's delivery"),
    ("mutation-gate-plan-", "plan", "the plan"),
    ("mutation-gate-judge-", "judge", "the judge's PHAR"),
    ("mutation-gate-coverage-", "coverage", "the plan's coverage maps"),
    ("mutation-gate-result-", "results", "the shards' results"),
    ("mutation-gate-publish-", "publish", "what the verdict publishes"),
    ("mutation-gate-verdict-", "verdict", "the verdict the last shard computed"),
)


class Unnamed(ValueError):
    """A download of an artifact `DOWNLOADS` does not name."""


def header() -> list[str]:
    return [
        f"# Generated from {TEMPLATE.as_posix()}/ by `{RECIPE}`: edit the template and run it again.",
        "",
    ]


def what(artifact: str) -> tuple[str, str]:
    """The id's slug and the retry's words for an artifact, by its name or pattern."""
    for start, slug, words in DOWNLOADS:
        if artifact.startswith(start):
            return slug, words
    raise Unnamed(f"no retry is named for a download of {artifact}; add it to DOWNLOADS")


def retried(step: list[str]) -> list[str]:
    """One download step of the template, as the timed step and its retry."""
    body = step[1:]
    artifact = next(line.split(": ", 1)[1] for line in body if line.strip().startswith(("name: ", "pattern: ")))
    slug, words = what(artifact)
    identity = f"download-{slug}"
    lenient = any(line.strip() == "continue-on-error: true" for line in body)
    condition = [line for line in body if line.strip().startswith("if: ")]
    rest = [line for line in body if not line.strip().startswith(("if: ", "continue-on-error: "))]
    return [
        step[0],
        f"        id: {identity}",
        *condition,
        f"        timeout-minutes: {MINUTES}",
        "        continue-on-error: true",
        *rest,
        f"      - name: Retry downloading {words}",
        f"        if: steps.{identity}.outcome == 'failure'",
        step[0].replace("      - uses:", "        uses:"),
        f"        timeout-minutes: {MINUTES}",
        *(["        continue-on-error: true"] if lenient else []),
        *rest,
    ]


def generated(template: str) -> str:
    """The workflow the template gives."""
    lines = template.split("\n")
    out = header()
    at = 0
    while at < len(lines):
        if not lines[at].startswith(DOWNLOAD):
            out.append(lines[at])
            at += 1
            continue
        end = at + 1
        while end < len(lines) and lines[end].startswith("        "):
            end += 1
        out += retried(lines[at:end])
        at = end
    return "\n".join(out)


def template() -> str:
    """The template's text: its parts, in order."""
    return "".join((ROOT / TEMPLATE / part).read_text(encoding="utf-8") for part in PARTS)


def main(argv: list[str]) -> int:
    if argv not in ([], ["--check"]):
        print(__doc__, file=sys.stderr)
        return 2
    try:
        written = generated(template())
    except Unnamed as unnamed:
        print(unnamed, file=sys.stderr)
        return 1
    if argv == ["--check"]:
        if (ROOT / WORKFLOW).read_text(encoding="utf-8") != written:
            print(f"{WORKFLOW.as_posix()} is not what {TEMPLATE.as_posix()} gives; run {RECIPE}", file=sys.stderr)
            return 1
        return 0
    (ROOT / WORKFLOW).write_text(written, encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
