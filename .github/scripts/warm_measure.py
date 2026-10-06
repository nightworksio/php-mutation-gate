#!/usr/bin/env python3
"""A warm run against fresh ones: what each took, and every verdict a warm worker alone gives.

Usage:
  warm_measure.py suspects <fresh report> <fork report>
  warm_measure.py judge --fresh <report> <seconds> [--fresh <report> <seconds> ...]
                        --fork <report> <seconds> [--fork <report> <seconds> ...] --findings <file>

Run by `warm workers` once it has run the gate on the same project and
coverage map with `runner.workers: fresh` and with `fork`, each writing the
JSON report (ADR-0023, decisions 12 to 14). `suspects` prints how many mutants
the forked run judged otherwise than the fresh one, so the job knows to run
each mode again. `judge` writes the first runs' wall time and the time their
mutants took to the step summary, and fails where every forked run gives a
mutant one verdict and every fresh run another, naming the mutant. A mutant
the runs of one mode disagree on is named in the summary as unsettled, and
fails nothing: a verdict that comes and goes in either mode cannot be pinned
on forking. The findings file
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


def statuses(fresh: list[dict], fork: list[dict]) -> dict[str, tuple[list[str], list[str], dict]]:
    """Each mutant's status in every fresh run and in every forked run, and where it is."""
    fresh_runs = [verdicts(report) for report in fresh]
    fork_runs = [verdicts(report) for report in fork]
    runs = fresh_runs + fork_runs
    found = {}
    for mutant_id in sorted(set().union(*(run.keys() for run in runs))):
        found[mutant_id] = (
            [run.get(mutant_id, {}).get("status", "absent") for run in fresh_runs],
            [run.get(mutant_id, {}).get("status", "absent") for run in fork_runs],
            next(run[mutant_id] for run in runs if mutant_id in run),
        )
    return found


def disagreements(fresh: list[dict], fork: list[dict]) -> list[dict]:
    """A finding for each mutant every fresh run gives one verdict, and every forked run another."""
    found = []
    for mutant_id, (fresh_statuses, fork_statuses, where) in statuses(fresh, fork).items():
        settled = len(set(fresh_statuses)) == 1 and len(set(fork_statuses)) == 1
        if settled and fresh_statuses[0] != fork_statuses[0]:
            found.append(
                {
                    "path": str(where.get("file", "")),
                    "line": int(where.get("line") or 0),
                    "rule": "verdict",
                    "detail": f"{mutant_id}: {fresh_statuses[0]} fresh, {fork_statuses[0]} forked",
                }
            )
    return found


def suspects(fresh: dict, fork: dict) -> int:
    """How many mutants one forked run judged otherwise than one fresh run."""
    return len(disagreements([fresh], [fork]))


def unsettled(fresh: list[dict], fork: list[dict]) -> list[str]:
    """Each mutant the runs of one mode disagree on, with every verdict it was given."""
    return [
        f"{mutant_id}: {', '.join(fresh_statuses)} fresh, {', '.join(fork_statuses)} forked"
        for mutant_id, (fresh_statuses, fork_statuses, _) in statuses(fresh, fork).items()
        if len(set(fresh_statuses)) > 1 or len(set(fork_statuses)) > 1
    ]


def mutant_seconds(report: dict) -> list[float]:
    """The seconds each mutant's run took, for each mutant a run judged."""
    return [
        float(mutant["seconds"])
        for mutant in report.get("mutants", [])
        if mutant.get("status") not in UNRUN and isinstance(mutant.get("seconds"), (int, float))
    ]


def table(fresh: dict, fresh_wall: float, fork: dict, fork_wall: float, varying: list[str]) -> str:
    """The step summary's comparison of the first fresh and forked runs, and the unsettled mutants."""
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
        text += "\nUnsettled, as the runs of one mode disagree among themselves:\n\n" + "".join(f"- {each}\n" for each in varying)
    return text


def read(path: str) -> dict:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def judge(argv: list[str]) -> int:
    runs: dict[str, list[tuple[dict, float]]] = {"--fresh": [], "--fork": []}
    findings = Path("warm-workers.json")
    at = 0
    while at < len(argv):
        if argv[at] in runs:
            runs[argv[at]].append((read(argv[at + 1]), float(argv[at + 2])))
            at += 3
        else:
            findings = Path(argv[at + 1])
            at += 2
    fresh = [report for report, _ in runs["--fresh"]]
    fork = [report for report, _ in runs["--fork"]]
    found = disagreements(fresh, fork)
    varying = unsettled(fresh, fork)
    findings.parent.mkdir(parents=True, exist_ok=True)
    findings.write_text(json.dumps(found), encoding="utf-8")
    text = table(fresh[0], runs["--fresh"][0][1], fork[0], runs["--fork"][0][1], varying)
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
