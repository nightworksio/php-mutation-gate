#!/usr/bin/env python3
"""A warm run against fresh ones: what each took, and every verdict a warm worker alone gives.

Usage:
  warm_measure.py suspects <fresh report> <fork report>
  warm_measure.py judge --fresh <report> <seconds> [--fresh <report> <seconds> ...]
                        --fork <report> <seconds> --findings <file>

Run by `warm workers` once it has run the gate on the same project and
coverage map with `runner.workers: fresh` and with `fork`, each writing the
JSON report (ADR-0023, decisions 12 to 14). `suspects` prints how many mutants
the forked run judged otherwise than the fresh one, so the job knows to run
fresh again. `judge` writes each run's wall time and the time its mutants took
to the step summary, and fails where the forked run's verdict differs from a
verdict every fresh run agrees on, naming the mutant. A mutant whose fresh
runs disagree among themselves is named in the summary as unsettled, and
fails nothing: no verdict of its can be pinned on forking. The findings file
holds the failing mutants as the job's evidence (ADR-0019, decision 5).

Two halves: `verdicts`, `disagreements`, `unsettled` and `table` decide from
the reports, and `main` reads the files.
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


def statuses(fresh: list[dict], fork: dict) -> dict[str, tuple[list[str], str, dict]]:
    """Each mutant's status in every fresh run and in the forked run, and where it is."""
    runs = [verdicts(report) for report in fresh]
    forked = verdicts(fork)
    ids = set(forked).union(*(run.keys() for run in runs))
    found = {}
    for mutant_id in sorted(ids):
        where = forked.get(mutant_id) or next((run[mutant_id] for run in runs if mutant_id in run), {})
        found[mutant_id] = (
            [run.get(mutant_id, {}).get("status", "absent") for run in runs],
            forked.get(mutant_id, {}).get("status", "absent"),
            where,
        )
    return found


def disagreements(fresh: list[dict], fork: dict) -> list[dict]:
    """A finding for each mutant every fresh run gives one verdict, and the forked run another."""
    found = []
    for mutant_id, (fresh_statuses, forked, where) in statuses(fresh, fork).items():
        settled = len(set(fresh_statuses)) == 1
        if settled and fresh_statuses[0] != forked:
            found.append(
                {
                    "path": str(where.get("file", "")),
                    "line": int(where.get("line") or 0),
                    "rule": "verdict",
                    "detail": f"{mutant_id}: {fresh_statuses[0]} fresh, {forked} forked",
                }
            )
    return found


def suspects(fresh: dict, fork: dict) -> int:
    """How many mutants the forked run judged otherwise than one fresh run."""
    return len(disagreements([fresh], fork))


def unsettled(fresh: list[dict], fork: dict) -> list[str]:
    """Each mutant whose fresh runs disagree among themselves, with every verdict it was given."""
    return [
        f"{mutant_id}: {', '.join(fresh_statuses)} fresh, {forked} forked"
        for mutant_id, (fresh_statuses, forked, _) in statuses(fresh, fork).items()
        if len(set(fresh_statuses)) > 1
    ]


def mutant_seconds(report: dict) -> list[float]:
    """The seconds each mutant's run took, for each mutant a run judged."""
    return [
        float(mutant["seconds"])
        for mutant in report.get("mutants", [])
        if mutant.get("status") not in UNRUN and isinstance(mutant.get("seconds"), (int, float))
    ]


def table(fresh: dict, fresh_wall: float, fork: dict, fork_wall: float, varying: list[str]) -> str:
    """The step summary's comparison of the first fresh run and the forked run, and the unsettled mutants."""
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
    text = "\n".join(lines) + f"\n\nThe forked run took {share} of the fresh run's wall time.\n"
    if varying:
        text += "\nUnsettled, as the fresh runs disagree among themselves:\n\n" + "".join(f"- {each}\n" for each in varying)
    return text


def read(path: str) -> dict:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def judge(argv: list[str]) -> int:
    fresh, fresh_walls, fork, fork_wall, findings = [], [], {}, 0.0, Path("warm-workers.json")
    at = 0
    while at < len(argv):
        if argv[at] == "--fresh":
            fresh.append(read(argv[at + 1]))
            fresh_walls.append(float(argv[at + 2]))
            at += 3
        elif argv[at] == "--fork":
            fork, fork_wall = read(argv[at + 1]), float(argv[at + 2])
            at += 3
        else:
            findings = Path(argv[at + 1])
            at += 2
    found = disagreements(fresh, fork)
    varying = unsettled(fresh, fork)
    findings.parent.mkdir(parents=True, exist_ok=True)
    findings.write_text(json.dumps(found), encoding="utf-8")
    text = table(fresh[0], fresh_walls[0], fork, fork_wall, varying)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as out:
            out.write("### Warm workers\n\n" + text)
    print(text)
    for finding in found:
        print(f"::error file={finding['path']},line={finding['line']}::{finding['detail']}")
    return 1 if found else 0


def main(argv: list[str]) -> int:
    if argv[1] == "suspects":
        print(suspects(read(argv[2]), read(argv[3])))
        return 0
    return judge(argv[2:])


if __name__ == "__main__":
    sys.exit(main(sys.argv))
