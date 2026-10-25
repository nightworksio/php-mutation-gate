#!/usr/bin/env python3
"""The benchmark's harness: each arm run on one project, interleaved, and what they did reconciled.

Usage:
  bench.py run <project file> <project directory> <out directory> [--rounds N] [--arm-seconds S]
  bench.py reconcile <project file> <project directory> <out directory> <result file>
  bench.py time <times> <result file> -- <command...>

Run by `bench.yml` once the project is prepared at its pinned commit
(ADR-0017). `run` runs every arm of the project once a
round, in turn, so no arm has the machine to itself at a quieter time, each
from a cold start: what an arm leaves is removed before it runs. It keeps each
arm's report and wall time in the out directory, taking the report from the
file an arm's `log` names where the tool writes it to a path of its config,
and the text log from the file its `text` names.
An arm whose `stdout` is set has what it printed kept as its report, as plain
Pest's is. An arm runs in the project directory, or in the copy its
`directory` names, as a plain arm runs in a copy the gate's `infection:patch`
or `pest:patch` never touched. With `--arm-seconds`, an arm still running
after that long is stopped, every process it started with it, and the round
records it as stopped: one slow arm leaves the other arms and rounds their
time. Whatever paths an arm's `keep` lists are copied into the out directory
after each round, stopped or not, so a stopped gate's plan, shard results
and coverage timings are kept with the artifact.

Plain Pest, run in parallel, names only its untested and uncovered mutants,
and counts the rest: its killed and timed-out mutants come to the totals
alone, and its mutants are matched with the gate's by file, line and mutator. `reconcile` writes the result
file and the step summary:

- each arm's wall time, as the median of the rounds with their range;
- each arm's mutants per file and status, from the first round that left
  a report;
- each mutant the arms named under `same` judge otherwise, or one of them
  lacks. Those arms mutate with the same tool and mutators, so a difference
  the gate's own statuses do not explain fails the benchmark. A mutant plain
  Infection skipped, which its text log names, is explained by the skip,
  one it timed out that the gate ran to a kill or a survivor by the limit,
  and one it killed or errored on that the gate killed or timed out by both
  detecting it.

`time` runs one command that many times and keeps the median of its wall
time with the range, as `Pest start-up` measures what starting Pest costs.

Two halves: `outcome`, `tally`, `pairs` and `summary` decide from the
reports, and `main` runs the arms and reads and writes the files.
"""
import json
import os
import platform
import re
import shutil
import signal
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

# Infection's status of a mutant it never ran, since the tests that cover it
# took longer than its timeout to run once. Its JSON log only counts them;
# its text log names each, with no diff. The gate runs it, so the difference
# is Infection's own.
SKIPPED = "skipped"

# Infection's status of a mutant whose run outlasted Infection's own limit,
# five times its covering tests' time within its `timeout`. The patched
# Infection the gate runs allows each mutant the gate's limit instead
# (ADR-0008), so where the gate ran it to a kill or a survivor, the limit
# alone tells the two apart.
TIMED_OUT = "timeouted"
RAN_TO_AN_END = ("killed", "escaped")

# The outcomes that both detect a mutant: one arm's kill and the other's
# timeout tell the same thing, as where a mutant's tests read random data,
# such as lcobucci/jwt's ECDSA signatures, and end it at once or loop on it.
DETECTED = {"killed", "timed out"}

# Infection's status of a mutant whose run ended in an error rather than a
# failed test, which Infection's own score counts as detected, as it does a
# kill: the gate's kill or timeout of it tells the same thing.
ERRORED = "errored"

# How a mutator of one of the gate's own sets is named, `<set>/<Name>`
# (ADR-0021): the plain tool has no such mutator, so it lacks its mutants.
GATE_SET = "/"
INFECTION_SKIPPED = re.compile(r"^\d+\) (\S+):(\d+)\s+\[M\] (\S+) \[ID\] \S+$")


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


def skipped(printed: str, root: str) -> list[dict]:
    """The mutants Infection's text log lists as skipped, by file, line and mutator."""
    found: list[dict] = []
    listing = False
    for line in printed.splitlines():
        if line.endswith("mutants:"):
            listing = line == "Skipped mutants:"
            continue
        named = INFECTION_SKIPPED.match(line) if listing else None
        if named:
            file, at, mutator = named.groups()
            found.append({"file": relative(file, root), "line": int(at), "mutator": mutator, "diff": (),
                          "status": SKIPPED, "outcome": "other"})
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


def unskipped(plain: list[dict], gate: list[dict]) -> tuple[list[dict], list[dict], list[dict]]:
    """
    Each mutant plain Infection skipped, matched with the gate's by file, line
    and mutator, since its text log gives no diff: the difference each makes,
    explained, and the mutants of both arms left to match by their diffs.
    """
    left = list(gate)
    found = []
    for mutant in (each for each in plain if each["status"] == SKIPPED):
        at = next((index for index, other in enumerate(left) if key(other, False) == key(mutant, False)), None)
        other = left.pop(at) if at is not None else None
        found.append({"file": mutant["file"], "line": mutant["line"], "mutator": mutant["mutator"], "plain": SKIPPED,
                      "gate": other["status"] if other else "absent", "explained": other is not None})
    return found, [each for each in plain if each["status"] != SKIPPED], left


def leaves_uncovered_out(arm: dict) -> bool:
    """Whether a plain arm makes no mutant on a line no test covers: Infection, unless `--with-uncovered` asks."""
    return arm["report"] == "infection" and "--with-uncovered" not in arm["command"]


def pairs(plain: list[dict], gate: list[dict], named_only: bool = False, left_out: bool = False) -> list[dict]:
    """
    Each mutant two arms of the same tool judge otherwise, or one lacks, and
    whether the gate explains it. Where the plain arm names only its escaped
    and uncovered mutants, as plain Pest does, the gate's are matched with
    those by file, line and mutator, and a gate mutant it does not name is
    one it killed or timed out. A mutant plain Infection skipped is matched
    by file, line and mutator, and Infection's skip explains it; one it timed
    out that the gate killed or that survived is explained by the limit, and
    one it killed or errored on that the gate killed or timed out by both
    detecting it. A gate
    mutant the plain arm lacks is explained where a mutator of the gate's
    own sets made it, or where it is uncovered and the plain arm leaves
    uncovered mutants out.
    """
    if named_only:
        gate = [mutant for mutant in gate if mutant["outcome"] in ("escaped", "uncovered")]
    skips, plain, gate = unskipped(plain, gate)
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
                or (one["status"] == TIMED_OUT and other["outcome"] in RAN_TO_AN_END)
                or {one["outcome"], other["outcome"]} == DETECTED
                or (one["status"] == ERRORED and other["outcome"] in DETECTED)
            ) or (one is None and other is not None and (
                GATE_SET in other["mutator"] or (left_out and other["outcome"] == "uncovered")
            ))
            found.append({
                "file": each[0],
                "line": each[1],
                "mutator": each[2],
                "plain": one["status"] if one else ("killed or timed out" if named_only else "absent"),
                "gate": other["status"] if other else "absent",
                "explained": explained,
            })
    return sorted([*skips, *found], key=lambda each: (each["file"], each["line"], each["mutator"]))


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
        "| arm | wall time, median (range) | generated | " + " | ".join(OUTCOMES) + " | exit codes |",
        "|---|---:|---:|" + "---:|" * len(OUTCOMES) + "---|",
    ]
    for name in names:
        arm = arms[name]
        total = arm["files"].get("total", dict.fromkeys(("generated", *OUTCOMES), 0))
        wall = arm["wall"]
        lines.append(
            f"| {name} | {wall['median']:.1f} s ({wall['min']:.1f}–{wall['max']:.1f}) | {total['generated']} | "
            + " | ".join(str(total[each]) for each in OUTCOMES)
            + f" | {', '.join(str(code) for code in arm['exits'])} |"
        )
    order = ", ".join(OUTCOMES)
    lines += ["", f"Each arm's mutants per file, from its first round that left a report (generated, then {order}):", ""]
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


def stop(process: subprocess.Popen) -> None:
    """A process stopped with every process it started, which share its session."""
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait()


def ended(returncode: int | None, stopped: bool) -> int | str:
    """How a round of an arm ended: its exit code, or `stopped` where it outran its time."""
    return "stopped" if stopped or returncode is None else returncode


def local() -> dict[str, str]:
    """This process's environment without CI's variables, so each tool runs as it would on a laptop."""
    return {name: value for name, value in os.environ.items() if name not in ("CI", "GITHUB_ACTIONS")}


def ran(command: list[str], place: Path, environment: dict, seconds: float | None,
        stdout=None) -> tuple[float, int | str]:
    """One command run in its own session, stopped with it after that long; its wall time and how it ended."""
    started = time.monotonic()
    process = subprocess.Popen(command, cwd=place, env=environment, start_new_session=True, stdout=stdout)
    stopped = False
    try:
        process.wait(timeout=seconds)
    except subprocess.TimeoutExpired:
        stopped = True
        stop(process)
    return time.monotonic() - started, ended(process.returncode, stopped)


def kept(place: Path, arm: dict, out: Path, name: str, at: int) -> None:
    """The paths an arm keeps, copied beside its report, each as it was when the round ended."""
    for each in arm.get("keep", []):
        source = place / each
        target = out / f"{name}-{at}.kept" / each
        if source.is_dir():
            shutil.copytree(source, target, dirs_exist_ok=True)
        elif source.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)


def run(project: dict, directory: Path, out: Path, rounds: int, arm_seconds: float | None = None) -> None:
    """Every arm once a round, in turn, each from a cold start, its report and wall time kept."""
    out.mkdir(parents=True, exist_ok=True)
    environment = local()
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
            command = [fill(part, report) for part in arm["command"]]
            with open(report if arm.get("stdout") else os.devnull, "w", encoding="utf-8") as printed:
                seconds, exit_code = ran(command, place, environment, arm_seconds,
                                         printed if arm.get("stdout") else None)
            if arm.get("log") and (place / arm["log"]).exists():
                shutil.move(place / arm["log"], report)
            if arm.get("text") and (place / arm["text"]).exists():
                shutil.move(place / arm["text"], out / f"{name}-{at}.txt")
            kept(place, arm, out, name, at)
            (out / f"{name}-{at}.time").write_text(json.dumps({"seconds": seconds, "exit": exit_code}))
            print(f"{name}, round {at}: {seconds:.1f} s, exit {exit_code}", flush=True)


def reconcile(project: dict, out: Path, result: Path, root: str) -> int:
    """The result file and the step summary; 1 where the same tool's arms differ in a way the gate does not explain."""
    arms: dict[str, dict] = {}
    first: dict[str, list[dict]] = {}
    for name, arm in project["arms"].items():
        times = sorted(out.glob(f"{name}-*.time"), key=lambda path: int(path.stem.rsplit("-", 1)[1]))
        runs = [json.loads(path.read_text()) for path in times]
        rounds = [int(path.stem.rsplit("-", 1)[1]) for path in times]
        at = next((each for each in rounds if report_of(out, name, arm, each).exists()), 1)
        report = report_of(out, name, arm, at)
        place = str((Path(root) / arm.get("directory", ".")).resolve())
        totals = None
        if not report.exists():
            first[name] = []
        elif arm["report"] == "pest":
            first[name], totals = pest(report.read_text(encoding="utf-8", errors="replace"))
        else:
            first[name] = outcome(json.loads(report.read_text()), arm["report"], place)
            text = out / f"{name}-{at}.txt"
            if arm.get("text") and text.exists():
                first[name] += skipped(text.read_text(encoding="utf-8", errors="replace"), place)
        arms[name] = {
            "said": arm["said"],
            "wall": spread([each["seconds"] for each in runs]),
            "exits": [each["exit"] for each in runs],
            "counted": at,
            "files": tally(first[name], totals),
        }
    same = project.get("same", [])
    named_only = any(project["arms"][name]["report"] == "pest" for name in same)
    left_out = bool(same) and leaves_uncovered_out(project["arms"][same[0]])
    differences = pairs(first[same[0]], first[same[1]], named_only, left_out) if len(same) == 2 else []
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
        arm_seconds = float(argv[argv.index("--arm-seconds") + 1]) if "--arm-seconds" in argv else None
        run(project, Path(argv[2]).resolve(), Path(argv[3]).resolve(), rounds, arm_seconds)
        return 0
    return reconcile(project, Path(argv[3]).resolve(), Path(argv[4]), str(Path(argv[2]).resolve()))


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
