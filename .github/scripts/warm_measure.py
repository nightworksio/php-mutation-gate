#!/usr/bin/env python3
"""A warm run against a fresh one: what each took, and every verdict they disagree on.

Usage: warm_measure.py <fresh report> <fresh seconds> <fork report> <fork seconds> --findings <file>

Run by `warm workers` once it has run the gate twice on the same project and
coverage map, once with `runner.workers: fresh` and once with `fork`, each
writing the JSON report (ADR-0023, decisions 12 to 14). It writes each run's
wall time and the time its mutants took to the step summary, and fails where a
mutant's verdict differs between the two, naming it. The findings file holds
those mutants as the job's evidence (ADR-0019, decision 5).

Two halves: `verdicts`, `disagreements` and `table` decide from the reports,
and `main` reads the files.
"""
import json
import os
import statistics
import sys
from pathlib import Path

# The verdicts no run of the mutant decides: no test covers it.
UNRUN = {"uncovered"}


def verdicts(report: dict) -> dict[str, dict]:
    """Each mutant of a report, by its id."""
    return {mutant["id"]: mutant for mutant in report.get("mutants", [])}


def disagreements(fresh: dict, fork: dict) -> list[dict]:
    """A finding for each mutant whose verdict differs between the runs, or that one run lacks."""
    a, b = verdicts(fresh), verdicts(fork)
    found = []
    for mutant_id in sorted(a.keys() | b.keys()):
        left, right = a.get(mutant_id, {}), b.get(mutant_id, {})
        if left.get("status") != right.get("status"):
            where = left or right
            found.append(
                {
                    "path": str(where.get("file", "")),
                    "line": int(where.get("line") or 0),
                    "rule": "verdict",
                    "detail": f"{mutant_id}: {left.get('status', 'absent')} fresh, {right.get('status', 'absent')} forked",
                }
            )
    return found


def mutant_seconds(report: dict) -> list[float]:
    """The seconds each mutant's run took, for each mutant a run judged."""
    return [
        float(mutant["seconds"])
        for mutant in report.get("mutants", [])
        if mutant.get("status") not in UNRUN and isinstance(mutant.get("seconds"), (int, float))
    ]


def table(fresh: dict, fresh_wall: float, fork: dict, fork_wall: float) -> str:
    """The step summary's comparison of the two runs."""
    runs = [(fresh, fresh_wall), (fork, fork_wall)]
    seconds = [mutant_seconds(report) for report, _ in runs]

    def row(name: str, cells: list[str]) -> str:
        return f"| {name} | {' | '.join(cells)} |"

    lines = [
        "| | fresh | fork |",
        "|---|---:|---:|",
        row("wall time", [f"{wall:.1f} s" for _, wall in runs]),
        row("mutants run", [str(len(each)) for each in seconds]),
        row("their summed time", [f"{sum(each):.1f} s" for each in seconds]),
        row("median mutant", [f"{statistics.median(each):.3f} s" if each else "-" for each in seconds]),
    ]
    share = f"{fork_wall / fresh_wall:.0%}" if fresh_wall > 0 else "-"
    return "\n".join(lines) + f"\n\nThe forked run took {share} of the fresh run's wall time.\n"


def main(argv: list[str]) -> int:
    findings_at = argv.index("--findings")
    fresh_path, fresh_wall, fork_path, fork_wall = argv[1:findings_at]
    findings = Path(argv[findings_at + 1])
    fresh = json.loads(Path(fresh_path).read_text(encoding="utf-8"))
    fork = json.loads(Path(fork_path).read_text(encoding="utf-8"))
    found = disagreements(fresh, fork)
    findings.parent.mkdir(parents=True, exist_ok=True)
    findings.write_text(json.dumps(found), encoding="utf-8")
    text = table(fresh, float(fresh_wall), fork, float(fork_wall))
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as out:
            out.write("### Warm workers\n\n" + text)
    print(text)
    for finding in found:
        print(f"::error file={finding['path']},line={finding['line']}::{finding['detail']}")
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
