#!/usr/bin/env python3
"""The benchmark's scenarios past a cold full run: warm full, PR replay and an unchanged re-run (ADR-0017).

Usage:
  bench_scenarios.py run <project file> <project directory> <out directory> [--rounds N] [--arm-seconds S]
  bench_scenarios.py report <project file> <out directory> <result file>

Run by `bench.yml` once the project is prepared at its pinned commit, as
`bench.py run` is. The project file's `scenarios` names the edit and the
commits:

- `edit`: the scripted edit, one source file and one test file, each with
  a local variable renamed throughout: a change to code whose behaviour
  stays the same, so it changes the keys of what reads it and no verdict;
- `replay`: the project's last 20 merged pull requests up to the pinned
  commit, or those of them whose code runs on the packages the preparation
  installs, oldest first, each by its number, the commit it merged into
  (`base`) and the commit that merged it (`merge`);
- `gate`: the arms the scenarios run, each of them one of the gate's;
- `diff`: where the runner is Infection, the plain arm that runs again
  with Infection's own `--git-diff-lines` for each pull request.

The prepared project is a git repository of one commit. Each commit a
scenario needs is fetched from the project's repository and laid over it:
every file the commit holds is written and every file the repository
tracks that the commit does not hold is removed, but those the
preparation wrote or changed, which stay as the preparation left them, so
every commit runs on one set of installed packages. Each overlay is a
commit of its own, on `main` for the default branch and on a branch of its
own for a pull request, and `origin/HEAD` names `main`, as a clone's does,
so a pull request's run reads the default branch's ledger.

For each gate arm, from an empty `.mutation-gate`:

- **warm full**: a full run at the pinned commit, untimed, then a full run
  at the pinned commit with the scripted edit on what it left, timed, each
  round from that same ledger;
- **unchanged re-run**: the trivial case, a full run at the pinned commit
  with the scripted edit on the ledger the warm full run left, timed;
- **PR replay**: a full run at the first pull request's base, untimed;
  then for each pull request, the default branch brought to its base by a
  run of what changed since the default branch's last commit, untimed, and
  a run of what the pull request changed, on a branch of its own, timed.

Each timed run keeps its report and wall time in the out directory, as
`<arm>-<scenario>-<round or pull request>`. `report` writes the result file
and the step summary: each warm full and unchanged re-run as the median of
its rounds with their range, and each arm's PR replay as the median and
90th percentile of its pull requests, with how many ended otherwise than
with a verdict.
"""
import json
import math
import os
import re
import shutil
import statistics
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

import bench

# The branch a scenario runs the default branch's commits on.
MAIN = "main"

# The exit codes of a run that reached a verdict, passing or failing.
VERDICTS = (0, 1)

# The scenarios whose timed run is repeated once a round; a PR replay runs each pull request once.
ROUNDED = ("warm", "unchanged")


def percentile(values: list[float], share: float) -> float:
    """The value at this share of the sorted values, by the nearest rank; none of no values is 0."""
    ordered = sorted(values)
    return ordered[max(math.ceil(share * len(ordered)) - 1, 0)] if ordered else 0.0


def owned(prepared: dict[str, bytes], pristine: dict[str, bytes]) -> set[str]:
    """The files the preparation wrote or changed: in the prepared commit, and not as the project holds them."""
    return {path for path, contents in prepared.items() if pristine.get(path) != contents}


def laid(tracked: set[str], tree: set[str], kept: set[str]) -> tuple[set[str], set[str]]:
    """What laying a commit over the repository writes and removes: never a file the preparation owns."""
    return tree - kept, tracked - tree - kept


def renamed(text: str, name: str, to: str) -> str:
    """The text with every `$name` variable renamed `$to`; an error where it has none or already has a `$to`."""
    if re.search(rf"\${re.escape(to)}\b", text):
        raise ValueError(f"${to} is already in the text")
    edited, count = re.subn(rf"\${re.escape(name)}\b", f"${to}", text)
    if count == 0:
        raise ValueError(f"${name} is not in the text")
    return edited


def scripted(directory: Path, edit: list[dict]) -> None:
    """The scripted edit made: each of its files with its variable renamed."""
    for each in edit:
        path = directory / each["file"]
        path.write_text(renamed(path.read_text(encoding="utf-8"), each["rename"], each["to"]), encoding="utf-8")


def plan(project: dict) -> list[dict]:
    """
    Every run of the scenarios for one arm, in order: the commit it runs at,
    whether with the scripted edit, on `main` or on a pull request's own
    branch, whether it is a full run or one of what changed since the
    default branch's last commit, whether it is timed, and whether it starts
    from an empty `.mutation-gate`.
    """
    scenarios = project["scenarios"]
    full = {"branch": MAIN, "full": True}
    steps = [
        {**full, "scenario": "warm", "at": project["commit"], "timed": False, "fresh": True},
        {**full, "scenario": "warm", "at": project["commit"], "edited": True, "timed": True},
        {**full, "scenario": "unchanged", "at": project["commit"], "edited": True, "timed": True},
    ]
    pulls = scenarios.get("replay", [])
    main = ""
    for pull in pulls:
        if not main:
            steps.append({**full, "scenario": "replay", "at": pull["base"], "timed": False, "fresh": True})
        elif pull["base"] != main:
            steps.append({"branch": MAIN, "full": False, "scenario": "replay", "at": pull["base"], "timed": False})
        main = pull["base"]
        steps.append({"branch": f"pull-{pull['pull']}", "full": False, "scenario": "replay", "at": pull["merge"],
                      "timed": True, "pull": pull["pull"]})
    return steps


def git(directory: Path, *arguments: str) -> str:
    done = subprocess.run(["git", "-C", str(directory), *arguments], capture_output=True, text=True)
    if done.returncode != 0:
        raise RuntimeError(f"git {' '.join(arguments)} in {directory} failed: {done.stderr.strip()}")
    return done.stdout


def committed(directory: Path, message: str) -> str:
    git(directory, "add", "--all")
    git(directory, "-c", "user.name=bench", "-c", "user.email=bench@localhost", "commit", "--quiet",
        "--allow-empty", "--message", message)
    return git(directory, "rev-parse", "HEAD").strip()


def contents(directory: Path, paths: set[str]) -> dict[str, bytes]:
    return {path: (directory / path).read_bytes() for path in paths if (directory / path).is_file()}


class Source:
    """The project's repository, each commit a scenario needs fetched once and its tree kept."""

    def __init__(self, repository: str, place: Path):
        self.repository = repository
        self.place = place
        self.trees: dict[str, Path] = {}
        place.mkdir(parents=True, exist_ok=True)
        git(place, "init", "--quiet")
        # A repository's info/attributes is read before its tree's own.
        (place / ".git" / "info" / "attributes").write_text("* -export-ignore -export-subst\n")

    def tree(self, commit: str) -> Path:
        if commit not in self.trees:
            git(self.place, "fetch", "--quiet", "--depth", "1", self.repository, commit)
            target = self.place.parent / f"tree-{commit}"
            target.mkdir()
            archive = self.place.parent / f"{commit}.tar"
            with open(archive, "wb") as written:
                subprocess.run(["git", "-C", str(self.place), "archive", "--format=tar", commit], check=True,
                               stdout=written)
            with tarfile.open(archive) as read:
                read.extractall(target, filter="tar")
            archive.unlink()
            self.trees[commit] = target
        return self.trees[commit]


def files_under(root: Path) -> set[str]:
    return {str(path.relative_to(root)) for path in root.rglob("*") if path.is_file() or path.is_symlink()}


def overlay(directory: Path, tree: Path, kept: set[str]) -> None:
    """A commit's tree laid over the repository, the files the preparation owns left as they are."""
    tracked = set(git(directory, "ls-files", "-z").split("\0")) - {""}
    written, removed = laid(tracked, files_under(tree), kept)
    for path in removed:
        (directory / path).unlink(missing_ok=True)
    for path in written:
        target = directory / path
        target.parent.mkdir(parents=True, exist_ok=True)
        if target.is_symlink() or target.exists():
            target.unlink()
        shutil.copy2(tree / path, target, follow_symlinks=False)


def checkout(directory: Path, branch: str, start: str) -> None:
    """The branch at this commit, with nothing a run left that the repository neither tracks nor ignores."""
    git(directory, "checkout", "--quiet", "--force", "-B", branch, start)
    git(directory, "clean", "--quiet", "--force", "-d")


def published(directory: Path) -> None:
    """`origin/main` at the default branch's last commit, and `origin/HEAD` naming it, as a clone has them."""
    git(directory, "update-ref", f"refs/remotes/origin/{MAIN}", MAIN)
    git(directory, "symbolic-ref", "refs/remotes/origin/HEAD", f"refs/remotes/origin/{MAIN}")


def name_of(step: dict, at: int) -> str:
    return f"{step['scenario']}-{step['pull'] if 'pull' in step else at}"


class Checkout:
    """
    The prepared repository as a scenario moves it: the commit `main` is at,
    the project's commit it holds, and whether with the scripted edit.
    """

    def __init__(self, directory: Path, source: Source, kept: set[str], start: str):
        self.directory = directory
        self.source = source
        self.kept = kept
        self.start = start
        self.head = start
        self.at = ""
        self.edited = False

    def reset(self) -> None:
        """`main` back at the prepared commit, with nothing the gate kept."""
        checkout(self.directory, MAIN, self.start)
        bench.removed(self.directory / ".mutation-gate")
        self.head, self.at, self.edited = self.start, "", False
        published(self.directory)

    def main(self, commit: str, edit: list[dict] | None = None) -> str:
        """
        `main` at the project's commit, with this scripted edit where one is
        given, laid over its last one where it holds another; the commit it
        was at.
        """
        before = self.head
        checkout(self.directory, MAIN, self.head)
        if (commit, bool(edit)) != (self.at, self.edited):
            if commit != self.at or self.edited:
                overlay(self.directory, self.source.tree(commit), self.kept)
            if edit:
                scripted(self.directory, edit)
            self.head = committed(self.directory, f"{commit} with the scripted edit" if edit else commit)
            self.at, self.edited = commit, bool(edit)
        published(self.directory)
        return before

    def pull(self, branch: str, commit: str) -> str:
        """A branch of its own from `main`, with the pull request's commit laid over it; the commit `main` is at."""
        checkout(self.directory, branch, self.head)
        overlay(self.directory, self.source.tree(commit), self.kept)
        committed(self.directory, commit)
        return self.head


def run(project: dict, directory: Path, out: Path, rounds: int, arm_seconds: float | None = None) -> None:
    """Every gate arm's scenarios, and the plain arm's runs of each pull request's change where it has one."""
    out.mkdir(parents=True, exist_ok=True)
    environment = bench.local()
    work = Path(tempfile.mkdtemp(prefix="bench-scenarios-"))
    source = Source(project["repository"], work / "source")
    tracked = set(git(directory, "ls-files", "-z").split("\0")) - {""}
    kept = owned(contents(directory, tracked), contents(source.tree(project["commit"]), tracked))
    git(directory, "branch", "--quiet", "-M", MAIN)
    prepared = git(directory, "rev-parse", "HEAD").strip()
    for name in project["scenarios"]["gate"]:
        arm = project["arms"][name]
        checkout(directory, MAIN, prepared)
        for to, copied in arm.get("copy", {}).items():
            shutil.copyfile(directory / copied, directory / to)
        moved = Checkout(directory, source, kept | set(arm.get("copy", {})), committed(directory, name))
        snapshot = work / f"{name}.warm"
        for step in plan(project):
            if step.get("fresh"):
                moved.reset()
            if step["branch"] == MAIN:
                since = moved.main(step["at"], project["scenarios"]["edit"] if step.get("edited") else None)
            else:
                since = moved.pull(step["branch"], step["at"])
            options = ["--full"] if step["full"] else [f"--changed-since={since}"]
            times = rounds if step["timed"] and step["scenario"] in ROUNDED else 1
            for at in range(1, times + 1):
                if step["scenario"] == "warm" and step["timed"]:
                    bench.removed(directory / ".mutation-gate")
                    shutil.copytree(snapshot, directory / ".mutation-gate")
                label = name_of(step, at)
                report = out / f"{name}-{label}.json"
                command = [bench.fill(part, report) for part in arm["command"]] + options
                seconds, exit_code = bench.ran(command, directory, environment, arm_seconds)
                if step["timed"]:
                    (out / f"{name}-{label}.time").write_text(json.dumps({"seconds": seconds, "exit": exit_code}))
                print(f"{name}, {label}{'' if step['timed'] else ' (untimed)'}: {seconds:.1f} s, exit {exit_code}",
                      flush=True)
            if step["scenario"] == "warm" and not step["timed"]:
                shutil.copytree(directory / ".mutation-gate", snapshot, dirs_exist_ok=True)
    checkout(directory, MAIN, prepared)
    diffed(project, directory, out, source, kept, environment, arm_seconds)


def diffed(project: dict, directory: Path, out: Path, source: Source, kept: set[str], environment: dict,
           arm_seconds: float | None) -> None:
    """The plain arm run on each pull request's change alone, with its tool's own diff option, where it has one."""
    name = project["scenarios"].get("diff")
    if not name:
        return
    arm = project["arms"][name]
    place = (directory / arm.get("directory", ".")).resolve()
    out.mkdir(parents=True, exist_ok=True)
    git(place, "branch", "--quiet", "-M", MAIN)
    start = git(place, "rev-parse", "HEAD").strip()
    for pull in project["scenarios"].get("replay", []):
        checkout(place, f"pull-{pull['pull']}", start)
        overlay(place, source.tree(pull["base"]), kept)
        base = committed(place, pull["base"])
        overlay(place, source.tree(pull["merge"]), kept)
        committed(place, pull["merge"])
        bench.removed(Path(tempfile.gettempdir()) / "infection")
        for each in arm.get("clean", []):
            bench.removed(place / each)
        label = f"replay-{pull['pull']}"
        report = out / f"{name}-{label}.json"
        command = [bench.fill(part, report) for part in arm["command"]]
        command += ["--git-diff-lines", f"--git-diff-base={base}"]
        seconds, exit_code = bench.ran(command, place, environment, arm_seconds)
        if arm.get("log") and (place / arm["log"]).exists():
            shutil.move(place / arm["log"], report)
        (out / f"{name}-{label}.time").write_text(json.dumps({"seconds": seconds, "exit": exit_code}))
        print(f"{name}, {label}: {seconds:.1f} s, exit {exit_code}", flush=True)


def timings(out: Path, name: str, scenario: str) -> list[dict]:
    return [json.loads(path.read_text()) for path in sorted(out.glob(f"{name}-{scenario}-*.time"))]


def units_run(out: Path, name: str, scenario: str) -> list[int | None]:
    """How many units each round of a scenario ran, as its report says; none for a round that wrote no report."""
    counts: list[int | None] = []
    for path in sorted(out.glob(f"{name}-{scenario}-*.json")):
        try:
            counts.append(int(json.loads(path.read_text())["run"]["units"]["run"]))
        except (OSError, ValueError, KeyError, TypeError):
            counts.append(None)
    return counts


def drifted(counts: list[int | None]) -> bool:
    """
    Whether a later round ran more units than the first. Each round of a
    warm full run starts from the same commit and ledger, and each unchanged
    re-run from a ledger that only grew, so one that runs more read other
    keys: a file an earlier round wrote entered them.
    """
    known = [each for each in counts if each is not None]
    return any(each > known[0] for each in known[1:])


def replayed(runs: list[dict]) -> dict:
    """A PR replay's pull requests: the median and 90th percentile of those that reached a verdict, and the rest."""
    judged = [each["seconds"] for each in runs if each["exit"] in VERDICTS]
    return {
        "pulls": len(runs),
        "judged": len(judged),
        "median": statistics.median(judged) if judged else 0.0,
        "p90": percentile(judged, 0.9),
        "otherwise": [each["exit"] for each in runs if each["exit"] not in VERDICTS],
    }


def summary(project: dict, arms: dict[str, dict]) -> str:
    """The step summary: each arm's warm full and unchanged re-run, and its PR replay."""
    edited = " and ".join(f"`${each['rename']}` renamed `${each['to']}` in `{each['file']}`"
                          for each in project["scenarios"]["edit"])
    lines = [
        f"## {project['project']} {project['version']}: scenarios",
        "",
        f"Warm full: a full run at {project['commit'][:12]} with the scripted edit, {edited}, on the ledger "
        "of one without it. Unchanged re-run: the trivial case, the same full run again. PR replay: the last "
        f"{len(project['scenarios'].get('replay', []))} merged pull requests, each run on what it changed, "
        "from its base, with the default branch's ledger.",
        "",
        "| arm | warm full, median (range) | unchanged re-run, median (range) | PR replay, median | PR replay, p90 "
        "| pull requests with no verdict |",
        "|---|---:|---:|---:|---:|---:|",
    ]
    for name, arm in arms.items():
        cells = [name]
        for scenario in ROUNDED:
            wall = arm.get(scenario)
            cells.append(f"{wall['median']:.1f} s ({wall['min']:.1f}–{wall['max']:.1f})" if wall else "-")
        replay = arm.get("replay")
        cells += [f"{replay['median']:.1f} s", f"{replay['p90']:.1f} s",
                  f"{len(replay['otherwise'])} of {replay['pulls']}"] if replay else ["-", "-", "-"]
        lines.append("| " + " | ".join(cells) + " |")
    for name, arm in arms.items():
        for scenario in ROUNDED:
            units = arm.get(scenario, {}).get("unitsRun", [])
            if drifted(units):
                ran = ", ".join("-" if each is None else str(each) for each in units)
                lines += ["", f"**{name}, {scenario}: its rounds ran {ran} units.** A later round ran more than "
                          "the first, so a file an earlier round wrote entered the keys the next one read."]
    return "\n".join(lines) + "\n"


def report(project: dict, out: Path, result: Path) -> int:
    """
    The result file and the step summary; 1 where a timed run of a gate arm
    reached no verdict, or a round of a warm full or unchanged re-run ran
    more units than its first.
    """
    scenarios = project["scenarios"]
    arms: dict[str, dict] = {}
    failed = False
    for name in scenarios["gate"]:
        arms[name] = {"said": project["arms"][name]["said"]}
        for scenario in ROUNDED:
            runs = timings(out, name, scenario)
            units = units_run(out, name, scenario)
            failed = failed or any(each["exit"] not in VERDICTS for each in runs) or drifted(units)
            arms[name][scenario] = {**bench.spread([each["seconds"] for each in runs]),
                                    "exits": [each["exit"] for each in runs], "unitsRun": units}
        arms[name]["replay"] = replayed(timings(out, name, "replay"))
    if scenarios.get("diff"):
        name = scenarios["diff"]
        arms[name] = {"said": f"{project['arms'][name]['said']}, with --git-diff-lines from each base",
                      "replay": replayed(timings(out, name, "replay"))}
    result.parent.mkdir(parents=True, exist_ok=True)
    result.write_text(json.dumps({
        "project": project["project"],
        "version": project["version"],
        "commit": project["commit"],
        "edit": scenarios["edit"],
        "replay": scenarios.get("replay", []),
        "gate": os.environ.get("GITHUB_SHA", ""),
        "machine": bench.machine(),
        "arms": arms,
    }, indent=2) + "\n")
    bench.told(summary(project, arms))
    return 1 if failed else 0


def main(argv: list[str]) -> int:
    if len(argv) < 4 or argv[0] not in ("run", "report"):
        print(__doc__, file=sys.stderr)
        return 2
    project = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
    if argv[0] == "run":
        rounds = int(argv[argv.index("--rounds") + 1]) if "--rounds" in argv else 3
        arm_seconds = float(argv[argv.index("--arm-seconds") + 1]) if "--arm-seconds" in argv else None
        run(project, Path(argv[2]).resolve(), Path(argv[3]).resolve(), rounds, arm_seconds)
        return 0
    return report(project, Path(argv[2]).resolve(), Path(argv[3]))


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
