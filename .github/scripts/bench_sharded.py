#!/usr/bin/env python3
"""The benchmark's sharded arm: the gate planned once, each shard run on a machine of its own, then judged (ADR-0017).

Usage:
  bench_sharded.py setup <out directory> <job> -- <command...>
  bench_sharded.py plan <project file> <project directory> <out directory>
  bench_sharded.py shard <project file> <project directory> <out directory> <shard> --planned <directory>
      [--rounds N] [--arm-seconds S]
  bench_sharded.py verdict <project file> <project directory> <out directory> --planned <directory> [--rounds N]
  bench_sharded.py report <project file> <out directory> <result file> [--rounds N]

Run by `bench.yml` as a matrix, each step on the project prepared at its
pinned commit as `bench.py run` has it. The project file's `sharded` names
the gate arm whose command and config it runs with, so its shards are cut at
the `shards.seconds` that config has, the default where it sets none.

- `setup` runs one command, the job's fetch and preparation, and keeps how
  long it took as that job's setup;
- `plan` makes a full plan from a cold start, timed, and keeps the gate's
  state as it left it, the plan and the coverage it measured, in `planned`
  in the out directory, writing the shards' ids as `shards=` to
  `GITHUB_OUTPUT`;
- `shard` runs one shard of that plan once a round, each round from the
  state `--planned` names, and keeps its result and wall time;
- `verdict` judges each round's results of every shard from that state,
  timed, its report kept;
- `report` writes the result file and the step summary.

A round's wall time is the plan's, the slowest shard's and the verdict's;
its runner time is the plan's, every shard's and the verdict's. Each with
the jobs' setup is given beside it, since every machine sets the project up
again: what sharding costs in runner time is shown, not hidden.

Two halves: `rounds`, `report` and `summary` decide from the kept times,
and `main` runs the gate and reads and writes the files.
"""
import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

import bench

# Where the gate keeps its state in the project, and its plan there.
STATE = ".mutation-gate"
PLAN = f"{STATE}/plan.json"
RESULTS = f"{STATE}/results"

# What the plan job leaves for the shards and the verdict, in the out directory.
PLANNED = "planned"


def gate(project: dict, *arguments: str) -> list[str]:
    """A gate command, as the gate arm's own command runs it: its program and its config."""
    command = project["arms"][project["sharded"]["arm"]]["command"]
    configs = [part for part in command if part.startswith("--config=")]
    return [command[0], *arguments, "--no-interaction", *configs]


def copied(project: dict, directory: Path) -> None:
    """The files the gate arm copies before it runs, as its baseline."""
    for to, source in project["arms"][project["sharded"]["arm"]].get("copy", {}).items():
        shutil.copyfile(directory / source, directory / to)


def timed(out: Path, name: str, seconds: float, exit_code: int | str) -> None:
    (out / f"{name}.time").write_text(json.dumps({"seconds": seconds, "exit": exit_code}))
    print(f"{name}: {seconds:.1f} s, exit {exit_code}", flush=True)


def kept(out: Path, name: str) -> dict:
    path = out / f"{name}.time"
    return json.loads(path.read_text()) if path.exists() else {"seconds": 0.0, "exit": "absent"}


def run(command: list[str], place: Path, seconds: float | None = None) -> tuple[float, int | str]:
    """One gate command in the project, as `bench.py run` runs an arm."""
    return bench.ran(command, place, bench.local(), seconds)


def setup(out: Path, job: str, command: list[str]) -> int:
    """The job's setup run, and how long it took kept as `setup-<job>`."""
    out.mkdir(parents=True, exist_ok=True)
    started = time.monotonic()
    code = subprocess.run(command, check=False).returncode
    timed(out, f"setup-{job}", time.monotonic() - started, code)
    return code


def head(directory: Path) -> str:
    return subprocess.run(["git", "-C", str(directory), "rev-parse", "HEAD"], capture_output=True, text=True,
                          check=True).stdout.strip()


def planned(project: dict, directory: Path, out: Path) -> int:
    """A full plan from a cold start, timed, kept for the shards with its shards' ids; 1 where it made none."""
    out.mkdir(parents=True, exist_ok=True)
    bench.removed(directory / STATE)
    copied(project, directory)
    seconds, exit_code = run(gate(project, "plan", "--full", "--ci=json"), directory)
    timed(out, "plan", seconds, exit_code)
    if not (directory / PLAN).exists():
        return 1
    shutil.copytree(directory / STATE, out / PLANNED, dirs_exist_ok=True)
    # The raw reports of the suite's coverage run: the shards read the map and their own maps the gate made of them.
    for report in [*(out / PLANNED / "coverage").glob("*.php"), *(out / PLANNED / "coverage").glob("*.xml")]:
        bench.removed(report)
    ids = [shard["id"] for shard in json.loads((directory / PLAN).read_text())["shards"]]
    (out / "shards.json").write_text(json.dumps(ids))
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as written:
            written.write(f"shards={json.dumps(ids)}\n")
    return 0


def restored(project: dict, directory: Path, planned_state: Path) -> None:
    """The project's gate state as the plan left it, refused where the plan was made on another commit."""
    copied(project, directory)
    bench.removed(directory / STATE)
    shutil.copytree(planned_state, directory / STATE)
    made_on = json.loads((directory / PLAN).read_text())["commit"]
    if made_on != head(directory):
        raise RuntimeError(f"The plan was made on {made_on}, and this job's preparation is at {head(directory)}.")


def shard(project: dict, directory: Path, out: Path, planned_state: Path, at: str, rounds: int,
          arm_seconds: float | None) -> None:
    """One shard once a round, each from the plan as it was made, its result and wall time kept."""
    out.mkdir(parents=True, exist_ok=True)
    for each in range(1, rounds + 1):
        restored(project, directory, planned_state)
        command = gate(project, "run", f"--plan={PLAN}", f"--shard={at}", f"--results={RESULTS}")
        seconds, exit_code = run(command, directory, arm_seconds)
        timed(out, f"shard-{at}-{each}", seconds, exit_code)
        result = directory / RESULTS / f"{at}.json"
        if result.exists():
            (out / f"results-{each}").mkdir(exist_ok=True)
            shutil.copyfile(result, out / f"results-{each}" / f"{at}.json")


def verdict(project: dict, directory: Path, out: Path, planned_state: Path, rounds: int) -> None:
    """Each round's results of every shard judged, timed, its report kept."""
    for each in range(1, rounds + 1):
        restored(project, directory, planned_state)
        report = out / f"gate-sharded-{each}.json"
        results = out / f"results-{each}"
        results.mkdir(exist_ok=True)
        command = gate(project, "verdict", f"--plan={PLAN}", f"--results={results}", f"--report=json:{report}")
        seconds, exit_code = run(command, directory)
        timed(out, f"verdict-{each}", seconds, exit_code)


def rounds_of(out: Path, ids: list[int], rounds: int) -> list[dict]:
    """
    Each round's wall and runner time, without the jobs' setup and with it:
    the plan's, the slowest shard's or every shard's, and the verdict's.
    """
    plan = kept(out, "plan")["seconds"]
    planning = kept(out, "setup-plan")["seconds"]
    judging = kept(out, "setup-verdict")["seconds"]
    found = []
    for each in range(1, rounds + 1):
        shards = [kept(out, f"shard-{at}-{each}") for at in ids]
        setups = [kept(out, f"setup-shard-{at}")["seconds"] for at in ids]
        judged = kept(out, f"verdict-{each}")
        running = [one["seconds"] for one in shards]
        slowest = max(running, default=0.0)
        with_setup = max((one + setup for one, setup in zip(running, setups)), default=0.0)
        found.append({
            "wall": plan + slowest + judged["seconds"],
            "runner": plan + sum(running) + judged["seconds"],
            "wallWithSetup": planning + plan + with_setup + judging + judged["seconds"],
            "runnerWithSetup": planning + plan + sum(running) + sum(setups) + judging + judged["seconds"],
            "exits": [one["exit"] for one in shards] + [judged["exit"]],
        })
    return found


def report(project: dict, out: Path, result: Path, rounds: int) -> int:
    """The result file and the step summary; 1 where a shard or a verdict reached no verdict."""
    ids = json.loads((out / "shards.json").read_text()) if (out / "shards.json").exists() else []
    found = rounds_of(out, ids, rounds)
    first = out / "gate-sharded-1.json"
    files = bench.tally(bench.outcome(json.loads(first.read_text()), "gate", "")) if first.exists() else {}
    arm = {
        "said": project["sharded"]["said"],
        "shards": len(ids),
        "machines": len(ids) + 2,
        **{figure: bench.spread([each[figure] for each in found])
           for figure in ("wall", "runner", "wallWithSetup", "runnerWithSetup")},
        "exits": [each["exits"] for each in found],
        "files": files,
    }
    result.parent.mkdir(parents=True, exist_ok=True)
    result.write_text(json.dumps({
        "project": project["project"],
        "version": project["version"],
        "commit": project["commit"],
        "gate": os.environ.get("GITHUB_SHA", ""),
        "machine": bench.machine(),
        "arms": {"gate-sharded": arm},
    }, indent=2) + "\n")
    bench.told(summary(project, arm))
    reached = all(code in (0, 1) for each in found for code in each["exits"])
    return 0 if reached and found and ids else 1


def summary(project: dict, arm: dict) -> str:
    """The step summary: the sharded arm's wall and runner time, each without setup and with it."""
    def cell(figure: str) -> str:
        spread = arm[figure]
        return f"{spread['median']:.1f} s ({spread['min']:.1f}–{spread['max']:.1f})"

    return "\n".join([
        f"## {project['project']} {project['version']}: the gate sharded",
        "",
        f"{arm['said']}: {arm['shards']} shards, each on a machine of its own, and the plan and the verdict on "
        "one each.",
        "",
        "| | median (range) |",
        "|---|---:|",
        f"| wall time | {cell('wall')} |",
        f"| runner time | {cell('runner')} |",
        f"| wall time with each job's setup | {cell('wallWithSetup')} |",
        f"| runner time with each job's setup | {cell('runnerWithSetup')} |",
    ]) + "\n"


def option(argv: list[str], name: str, default: str | None) -> str | None:
    return argv[argv.index(name) + 1] if name in argv else default


def main(argv: list[str]) -> int:
    if argv[:1] == ["setup"] and "--" in argv and len(argv) > argv.index("--") + 1:
        return setup(Path(argv[1]), argv[2], argv[argv.index("--") + 1:])
    wanted = {"plan": 4, "shard": 5, "verdict": 4, "report": 4}
    if not argv or argv[0] not in wanted or len(argv) < wanted[argv[0]]:
        print(__doc__, file=sys.stderr)
        return 2
    project = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
    rounds = int(option(argv, "--rounds", "3") or "3")
    if argv[0] == "report":
        return report(project, Path(argv[2]).resolve(), Path(argv[3]), rounds)
    directory, out = Path(argv[2]).resolve(), Path(argv[3]).resolve()
    if argv[0] == "plan":
        return planned(project, directory, out)
    state = Path(option(argv, "--planned", str(out / PLANNED)) or "").resolve()
    seconds = option(argv, "--arm-seconds", None)
    if argv[0] == "shard":
        shard(project, directory, out, state, argv[4], rounds, float(seconds) if seconds else None)
        return 0
    verdict(project, directory, out, state, rounds)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
