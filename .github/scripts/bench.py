#!/usr/bin/env python3
"""The benchmark's harness: each arm run on one project, interleaved, and what they did reconciled.

Usage:
  bench.py run <project file> <project directory> <out directory> [--rounds N]
  bench.py reconcile <project file> <project directory> <out directory> <result file>
  bench.py time <times> <result file> -- <command...>

Run by `bench.yml` once the project is prepared at its pinned commit
(ADR-0017, decisions 14 to 17). `run` runs every arm of the project once a
round, in turn, so no arm has the machine to itself at a quieter time, each
from a cold start: what an arm leaves is removed before it runs. It keeps each
arm's report, wall time and what it printed in the out directory, taking the report from the
file an arm's `log` names where the tool writes it to a path of its config.
An arm whose `stdout` is set has what it printed kept as its report, as plain
Pest's is. An arm runs in the project directory, or in the copy its
`directory` names, as a plain arm runs in a copy the gate's `infection:patch`
or `pest:patch` never touched.

Plain Pest, run in parallel, names only its untested and uncovered mutants,
and counts the rest: its killed and timed-out mutants come to the totals
alone, and its mutants are matched with the gate's by file, line and mutator. `reconcile` writes the result
file and the step summary:

- each arm's wall time, as the median of the rounds with their range;
- each arm's mutants per file and status, from its first round;
- each mutant the arms named under `same` judge otherwise, or one of them
  lacks. Those arms mutate with the same tool and mutators, so a difference
  the gate's own statuses do not explain fails the benchmark (decision 17).

`time` runs one command that many times and keeps the median of its wall
time with the range, as `Pest start-up` measures what starting Pest costs.

Two halves: `outcome`, `tally`, `pairs` and `summary` decide from the
reports, and `main` runs the arms and reads and writes the files.
"""
import contextlib
import json
import os
import platform
import re
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path

# What every mutant comes to, in the order the tables give them.
OUTCOMES = ("killed", "escaped", "timed out", "uncovered", "other")

# Infection's JSON log: each list it keeps, and the outcome it holds.
INFECTION_LISTS = {
    "killed": "killed",
    "escaped": "escaped",
    "timeouted": "timed out",
    "uncovered": "uncovered",
    "errored": "other",
    "syntaxErrors": "other",
    "skipped": "other",
    "ignored": "other",
}

# The gate's statuses, and the outcome each comes to.
GATE_STATUSES = {
    "killed": "killed",
    "killed-by-static-analysis": "killed",
    "survived": "escaped",
    "timed-out": "timed out",
    "uncovered": "uncovered",
}

# What the gate's own statuses explain where the plain arm says otherwise:
# an analyser killed what no test does (ADR-0020), or a survivor was
# confirmed flaky or equivalent (ADR-0007).
EXPLAINED = {
    ("escaped", "killed-by-static-analysis"),
}
EXPLAINING_JUDGEMENTS = {"flaky", "equivalent"}


def changed(diff: str) -> tuple[str, ...]:
    """The lines a diff takes out and puts in, each with no indentation, and no headers or context."""
    return tuple(
        f"{line[0]}{line[1:].strip()}"
        for line in diff.splitlines()
        if line[:1] in ("+", "-") and not line.startswith(("+++", "---"))
    )


def relative(path: str, root: str) -> str:
    """A path as the project spells it."""
    prefix = root.rstrip("/") + "/"
    return path[len(prefix):] if path.startswith(prefix) else path


# Plain Pest's lines: a mutant it names, and its counts of what it ran.
PEST_NAMED = re.compile(r"^\s*(UNTESTED|UNCOVERED)\s+(\S+)\s+>\s+Line (\d+): (\S+) - ID: (\S+)")
PEST_COUNTS = re.compile(r"^\s*Mutations:\s+(.*)$")
PEST_COUNTED = {"untested": "escaped", "uncovered": "uncovered", "timeout": "timed out", "tested": "killed",
                "pending": "other"}


def pest(printed: str) -> tuple[list[dict], dict[str, int]]:
    """The mutants plain Pest names, each with the diff it prints after it, and its counts by outcome."""
    found: list[dict] = []
    counts = dict.fromkeys(OUTCOMES, 0)
    diff: list[str] = []
    for line in printed.splitlines():
        named = PEST_NAMED.match(line)
        totals = PEST_COUNTS.match(line)
        if named or totals:
            if found:
                found[-1]["diff"] = changed("\n".join(diff))
            diff = []
        if named:
            label, file, at, mutator, _ = named.groups()
            status = label.lower()
            found.append({"file": file, "line": int(at), "mutator": mutator, "diff": (), "status": status,
                          "outcome": PEST_COUNTED["untested" if status == "untested" else "uncovered"]})
        elif totals:
            for number, word in re.findall(r"(\d+) (\w+)", totals.group(1)):
                counts[PEST_COUNTED.get(word, "other")] += int(number)
        elif found:
            diff.append(line)
    return found, counts


def outcome(report: dict, kind: str, root: str) -> list[dict]:
    """Every mutant of a report: where it is, its mutator and diff, its status and what that comes to."""
    found = []
    if kind == "infection":
        for listed, comes_to in INFECTION_LISTS.items():
            for entry in report.get(listed, []):
                mutator = entry.get("mutator", {})
                found.append({
                    "file": relative(str(mutator.get("originalFilePath", "")), root),
                    "line": int(mutator.get("originalStartLine") or 0),
                    "mutator": str(mutator.get("mutatorName", "")),
                    "diff": changed(str(entry.get("diff", ""))),
                    "status": listed,
                    "outcome": comes_to,
                })
        return found
    for mutant in report.get("mutants", []):
        status = str(mutant.get("status", ""))
        found.append({
            "file": relative(str(mutant.get("file", "")), root),
            "line": int(mutant.get("line") or 0),
            "mutator": str(mutant.get("mutator", "")).rsplit("\\", 1)[-1],
            "diff": changed(str(mutant.get("diff", ""))),
            "status": status,
            "judgement": str(mutant.get("judgement", "")),
            "outcome": GATE_STATUSES.get(status, "other"),
        })
    return found


def tally(mutants: list[dict], totals: dict[str, int] | None = None) -> dict[str, dict[str, int]]:
    """Each file's mutants by outcome, with the whole run under `total`, or the totals the tool counted."""
    counts: dict[str, dict[str, int]] = {}
    for mutant in mutants:
        for key in (mutant["file"], "total"):
            row = counts.setdefault(key, dict.fromkeys(("generated", *OUTCOMES), 0))
            row["generated"] += 1
            row[mutant["outcome"]] += 1
    if totals is not None:
        counts["total"] = {"generated": sum(totals.values()), **totals}
    return counts


def key(mutant: dict, by_diff: bool) -> tuple:
    return (mutant["file"], mutant["line"], mutant["mutator"], mutant["diff"] if by_diff else ())


def pairs(plain: list[dict], gate: list[dict], named_only: bool = False) -> list[dict]:
    """
    Each mutant two arms of the same tool judge otherwise, or one lacks, and
    whether the gate explains it. Where the plain arm names only its escaped
    and uncovered mutants, as plain Pest does, the gate's are matched with
    those by file, line and mutator, and a gate mutant it does not name is
    one it killed or timed out.
    """
    if named_only:
        gate = [mutant for mutant in gate if mutant["outcome"] in ("escaped", "uncovered")]
    left: dict[tuple, list[dict]] = {}
    right: dict[tuple, list[dict]] = {}
    for mutants, by in ((plain, left), (gate, right)):
        for mutant in mutants:
            by.setdefault(key(mutant, by_diff=not named_only), []).append(mutant)
    found = []
    for each in sorted(set(left) | set(right), key=lambda k: (k[0], k[1], k[2], k[3])):
        ours, theirs = left.get(each, []), right.get(each, [])
        for at in range(max(len(ours), len(theirs))):
            one = ours[at] if at < len(ours) else None
            other = theirs[at] if at < len(theirs) else None
            if one and other and one["outcome"] == other["outcome"]:
                continue
            explained = bool(one and other) and (
                (one["outcome"], other["status"]) in EXPLAINED
                or other.get("judgement") in EXPLAINING_JUDGEMENTS
            )
            found.append({
                "file": each[0],
                "line": each[1],
                "mutator": each[2],
                "plain": one["status"] if one else ("killed or timed out" if named_only else "absent"),
                "gate": other["status"] if other else "absent",
                "explained": explained,
            })
    return found


def spread(seconds: list[float]) -> dict:
    """The median of the rounds, with their range."""
    return {
        "median": statistics.median(seconds) if seconds else 0.0,
        "min": min(seconds, default=0.0),
        "max": max(seconds, default=0.0),
        "rounds": seconds,
    }


def summary(project: dict, arms: dict[str, dict], differences: list[dict], machine: dict) -> str:
    """The step summary: the machine, each arm's time and counts, and the differences."""
    names = list(arms)
    lines = [
        f"## {project['project']} {project['version']} at {project['commit'][:12]}",
        "",
        f"{machine['cpu']}, {machine['cores']} cores, {machine['memory']}; PHP {machine['php']}.",
        "",
        "| arm | wall time, median (range) | generated | " + " | ".join(OUTCOMES)
        + " | exit codes | generated / escaped, each round |",
        "|---|---:|---:|" + "---:|" * len(OUTCOMES) + "---|---|",
    ]
    for name in names:
        arm = arms[name]
        total = arm["files"].get("total", dict.fromkeys(("generated", *OUTCOMES), 0))
        wall = arm["wall"]
        lines.append(
            f"| {name} | {wall['median']:.1f} s ({wall['min']:.1f}–{wall['max']:.1f}) | {total['generated']} | "
            + " | ".join(str(total[each]) for each in OUTCOMES)
            + f" | {', '.join(str(code) for code in arm['exits'])} | "
            + ", ".join(f"{each.get('generated', 0)} / {each.get('escaped', 0)}" for each in arm.get("rounds", []))
            + " |"
        )
    order = ", ".join(OUTCOMES)
    lines += ["", f"Each arm's mutants per file, from its first round (generated, then {order}):", ""]
    lines += ["| file | " + " | ".join(names) + " |", "|---|" + "---|" * len(names)]
    files = sorted({file for arm in arms.values() for file in arm["files"] if file != "total"})
    for file in files:
        cells = []
        for name in names:
            row = arms[name]["files"].get(file)
            cells.append(" / ".join(str(row[each]) for each in ("generated", *OUTCOMES)) if row else "-")
        lines.append(f"| {file} | " + " | ".join(cells) + " |")
    same = project.get("same", [])
    unexplained = [each for each in differences if not each["explained"]]
    lines += ["", f"{' and '.join(same)} mutate with the same tool and mutators. "
              f"Mutants they judge otherwise or one lacks: {len(differences)}, "
              f"of which unexplained: {len(unexplained)}."]
    if differences:
        lines += ["", "| file | line | mutator | " + " | ".join(same) + " | explained |", "|---|---:|---|---|---|---|"]
        for each in differences:
            lines.append(
                f"| {each['file']} | {each['line']} | {each['mutator']} | {each['plain']} | {each['gate']} | "
                f"{'yes' if each['explained'] else 'no'} |"
            )
    return "\n".join(lines) + "\n"


def machine() -> dict:
    """The CPU, its cores and memory, and PHP, as the result records them."""
    cpu, memory = platform.processor() or "unknown CPU", "unknown memory"
    info = Path("/proc/cpuinfo")
    if info.exists():
        names = [
            line.split(":", 1)[1].strip() for line in info.read_text().splitlines() if line.startswith("model name")
        ]
        cpu = names[0] if names else cpu
    meminfo = Path("/proc/meminfo")
    if meminfo.exists():
        total = next((line.split()[1] for line in meminfo.read_text().splitlines() if line.startswith("MemTotal")), "")
        memory = f"{int(total) / 1048576:.1f} GiB" if total else memory
    php = subprocess.run(["php", "-r", "echo PHP_VERSION;"], capture_output=True, text=True, check=False).stdout
    return {"cpu": cpu, "cores": os.cpu_count() or 0, "memory": memory, "php": php or "unknown"}


def report_of(out: Path, name: str, arm: dict, at: int) -> Path:
    """Where an arm's report of a round is kept: what it printed as text, else its own JSON."""
    return out / f"{name}-{at}.{'txt' if arm.get('stdout') else 'json'}"


def fill(text: str, report: Path) -> str:
    return text.replace("{report}", str(report))


def told(text: str) -> None:
    """Text for the step summary, where there is one, and for the log."""
    step = os.environ.get("GITHUB_STEP_SUMMARY")
    if step:
        with open(step, "a", encoding="utf-8") as written:
            written.write(text)
    print(text, end="")


def removed(target: Path) -> None:
    if target.is_dir():
        shutil.rmtree(target, ignore_errors=True)
    else:
        target.unlink(missing_ok=True)


def run(project: dict, directory: Path, out: Path, rounds: int) -> None:
    """Every arm once a round, in turn, each from a cold start, its report and wall time kept."""
    out.mkdir(parents=True, exist_ok=True)
    environment = {name: value for name, value in os.environ.items() if name not in ("CI", "GITHUB_ACTIONS")}
    for at in range(1, rounds + 1):
        for name, arm in project["arms"].items():
            report = report_of(out, name, arm, at)
            place = (directory / arm.get("directory", ".")).resolve()
            # Infection keeps what it made in a run under the system's temporary directory.
            removed(Path(tempfile.gettempdir()) / "infection")
            for each in arm.get("clean", []):
                removed(place / each)
            for to, source in arm.get("copy", {}).items():
                shutil.copyfile(place / source, place / to)
            started = time.monotonic()
            command = [fill(part, report) for part in arm["command"]]
            with (
                open(out / f"{name}-{at}.log", "w", encoding="utf-8") as log,
                open(report, "w", encoding="utf-8") if arm.get("stdout") else contextlib.nullcontext(log) as printed,
            ):
                done = subprocess.run(command, cwd=place, env=environment, check=False, stdout=printed, stderr=log)
            seconds = time.monotonic() - started
            if arm.get("log") and (place / arm["log"]).exists():
                shutil.move(place / arm["log"], report)
            (out / f"{name}-{at}.time").write_text(json.dumps({"seconds": seconds, "exit": done.returncode}))
            print(f"{name}, round {at}: {seconds:.1f} s, exit {done.returncode}", flush=True)


def read(report: Path, kind: str, root: str) -> tuple[list[dict], dict[str, int] | None]:
    """A round's mutants, and the totals the tool counted where it names only some; none where it wrote nothing."""
    if not report.exists():
        return [], None
    if kind == "pest":
        return pest(report.read_text(encoding="utf-8", errors="replace"))
    try:
        return outcome(json.loads(report.read_text(encoding="utf-8")), kind, root), None
    except json.JSONDecodeError:
        return [], None


def reconcile(project: dict, out: Path, result: Path, root: str) -> int:
    """The result file and the step summary; 1 where the same tool's arms differ in a way the gate does not explain."""
    arms: dict[str, dict] = {}
    first: dict[str, list[dict]] = {}
    for name, arm in project["arms"].items():
        times = sorted(out.glob(f"{name}-*.time"), key=lambda path: int(path.stem.rsplit("-", 1)[1]))
        runs = [json.loads(path.read_text()) for path in times]
        place = str((Path(root) / arm.get("directory", ".")).resolve())
        rounds = [read(report_of(out, name, arm, at), arm["report"], place) for at in range(1, len(runs) + 1)]
        first[name] = rounds[0][0] if rounds else []
        arms[name] = {
            "said": arm["said"],
            "wall": spread([each["seconds"] for each in runs]),
            "exits": [each["exit"] for each in runs],
            "files": tally(*rounds[0]) if rounds else {},
            "rounds": [tally(*each).get("total", {}) for each in rounds],
        }
    same = project.get("same", [])
    named_only = any(project["arms"][name]["report"] == "pest" for name in same)
    differences = pairs(first[same[0]], first[same[1]], named_only) if len(same) == 2 else []
    found = machine()
    result.parent.mkdir(parents=True, exist_ok=True)
    result.write_text(json.dumps({
        "project": project["project"],
        "version": project["version"],
        "commit": project["commit"],
        "gate": os.environ.get("GITHUB_SHA", ""),
        "machine": found,
        "arms": arms,
        "differences": differences,
    }, indent=2) + "\n")
    told(summary(project, arms, differences, found))
    return 1 if any(not each["explained"] for each in differences) else 0


def timed(times: int, result: Path, command: list[str]) -> int:
    """One command run this many times, the median of its wall time with the range kept; 1 where a run failed."""
    seconds, failed = [], False
    for _ in range(times):
        started = time.monotonic()
        failed = subprocess.run(command, check=False, capture_output=True).returncode != 0 or failed
        seconds.append(time.monotonic() - started)
    measured = {"command": command, **spread(seconds), "machine": machine()}
    result.parent.mkdir(parents=True, exist_ok=True)
    result.write_text(json.dumps(measured, indent=2) + "\n")
    told(f"`{' '.join(command)}`: {measured['median']:.2f} s median of {times} "
         f"({measured['min']:.2f}–{measured['max']:.2f}).\n")
    return 1 if failed else 0


def main(argv: list[str]) -> int:
    if argv[:1] == ["time"] and "--" in argv and len(argv) > argv.index("--") + 1:
        return timed(int(argv[1]), Path(argv[2]), argv[argv.index("--") + 1:])
    if len(argv) < 4 or argv[0] not in ("run", "reconcile") or (argv[0] == "reconcile" and len(argv) < 5):
        print(__doc__, file=sys.stderr)
        return 2
    project = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
    if argv[0] == "run":
        rounds = int(argv[argv.index("--rounds") + 1]) if "--rounds" in argv else 3
        run(project, Path(argv[2]).resolve(), Path(argv[3]).resolve(), rounds)
        return 0
    return reconcile(project, Path(argv[3]).resolve(), Path(argv[4]), str(Path(argv[2]).resolve()))


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
