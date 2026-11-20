#!/usr/bin/env python3
"""The newest merged pull requests, each planned again by this checkout's gate, so a plan that widens says why.

Usage: replay.py <count> --map <directory> --findings <file> --table <file>

Run by `replay` (ADR-0067). Each of the newest `count` pull requests
squashed onto main, read from HEAD's first-parent history, is planned again
in a clone of this checkout of its own, under a temporary directory, with
this checkout's vendor and ledger: two commits are made on top of HEAD, the
first with every path the pull request changed as it stood before it, the
second with each as the pull request left it. The clone moves to the second,
on the branch main and outside CI, so it reads no change since the ledger
(ADR-0060), and this checkout's gate runs
`plan --selection-only --changed-since=<first>` there with the coverage map
in `--map` and the default branch's ledger the directory store holds: the
selection alone, which is all a replay reads (ADR-0290). This checkout and
its branches stay as they are. A plan of no change on
HEAD itself gives the units that run whatever changed, the ones the ledger
cannot carry, and a pull request's reach is what it runs beyond them.

The findings file names each widening reason that quotes no path the pull
request changed, and each pull request that changed only documentation and
reached a unit. The table file holds a Markdown row for each pull request:
its paths, the units it reached and why it widened, and why each the
checkout's gate could not plan was skipped. Each plan says on standard error,
as it ends, how long it took and how many units it selected; one that takes
longer than PLAN_SECONDS is ended, its process group's processes printed.

Two halves: `merged`, `reached`, `widening`, `judge` and `table` decide from
text and plans alone, and `main` runs git and the gate.
"""
import json
import os
import re
import signal
import subprocess
import sys
import shutil
import tempfile
import time
from pathlib import Path

# The branch whose history holds the pull requests, and whose scope the plan runs in.
BRANCH = "main"

# A squashed pull request's subject ends in its number.
SUBJECT = re.compile(r"^([0-9a-f]{40})\t.*\(#(\d+)\)$")

# A path a reason quotes.
QUOTED = re.compile(r"`([^`]+)`")

# What is documentation alone: no rule of the gate reads it as code, a test or what decides how it runs.
DOCUMENTATION = (".docs/", ".site/")
DOCUMENT_SUFFIX = ".md"

# The variables that say a CI runs the job, which the plan must not read: it runs as a local plan of the branch.
CI_VARIABLES = re.compile(r"^(CI|TF_BUILD|GITHUB_.*|RUNNER_.*|ACTIONS_.*)$")

# How long one plan of the selection alone may take before it is ended and its processes printed.
PLAN_SECONDS = 240

# The checkout's directories a replay's clone takes along: the runner it plans with, and the ledger it reads.
TAKEN = ("vendor", ".mutation-gate/ledger")

# Who the replay's commits are made by; they are never pushed.
IDENTITY = {
    "GIT_AUTHOR_NAME": "replay", "GIT_AUTHOR_EMAIL": "replay@invalid",
    "GIT_COMMITTER_NAME": "replay", "GIT_COMMITTER_EMAIL": "replay@invalid",
}


def merged(log: str, count: int) -> list[tuple[int, str]]:
    """The newest `count` squashed pull requests in `git log --format=%H%x09%s` output, newest first: number and commit."""
    found = []
    for line in log.splitlines():
        matched = SUBJECT.match(line)
        if matched:
            found.append((int(matched.group(2)), matched.group(1)))
    return found[:count]


def reached(plan: dict) -> set[str]:
    """Every unit a plan runs, by its path."""
    return {unit["path"] for shard in plan.get("shards", []) for unit in shard.get("units", [])}


def is_documentation(path: str) -> bool:
    """Whether a path is documentation alone."""
    return path.startswith(DOCUMENTATION) or path.endswith(DOCUMENT_SUFFIX) or path == "LICENSE"


def widening(plan: dict) -> list[str]:
    """The reasons a plan gives for reaching every unit of a package, in its order."""
    reasons = plan.get("reach", [])
    return [reasons[at] for at in plan.get("widening", []) if 0 <= at < len(reasons)]


def judge(number: int, paths: list[str], plan: dict, beyond: set[str]) -> list[dict]:
    """A finding for each widening reason that quotes no changed path, and for documentation that reached a unit."""
    changed = set(paths)
    found = [
        {"path": "", "line": 0, "rule": "unjustified-widening", "detail": f"#{number}: {reason}"}
        for reason in widening(plan)
        if not changed.intersection(QUOTED.findall(reason))
    ]
    if paths and all(is_documentation(path) for path in paths) and beyond:
        found.append({"path": "", "line": 0, "rule": "documentation-reaches",
                      "detail": f"#{number} changed only documentation and reached {len(beyond)} units, "
                                f"{', '.join(sorted(beyond)[:5])} first."})
    return found


def table(rows: list[dict], skipped: list[dict]) -> str:
    """The replays as a Markdown table, a row each, in the order given, and why each skipped one could not be planned."""
    lines = ["| Pull request | Paths | Units reached | Widened by |", "|---|---:|---:|---|"]
    for row in rows:
        widened = "<br>".join(row["widening"]) or "nothing"
        lines.append(f"| #{row['number']} | {row['paths']} | {row['units']} | {widened} |")
    lines += ["", *(f"- #{each['number']} could not be planned on this checkout: {each['why']}" for each in skipped)]
    return "\n".join(lines).rstrip("\n") + "\n"


def git(*args: str, env: dict | None = None) -> str:
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True,
                          env={**os.environ, **(env or {})}).stdout


def tree_with(base: str, commit: str, paths: list[str]) -> str:
    """The tree of `base`, with each path as it is at `commit`, and without each `commit` does not hold."""
    with tempfile.TemporaryDirectory() as work:
        index = {"GIT_INDEX_FILE": str(Path(work) / "index")}
        git("read-tree", base, env=index)
        for path in paths:
            listed = git("ls-tree", commit, "--", path).split()
            if listed:
                git("update-index", "--add", "--cacheinfo", f"{listed[0]},{listed[2]},{path}", env=index)
            else:
                git("update-index", "--force-remove", "--", path, env=index)
        return git("write-tree", env=index).strip()


def planned(gate: Path, at: str, since: str, coverage: Path) -> dict:
    """The selection a gate plans of this clone at a commit, on the branch and outside CI, of the change since a ref.

    The clone's vendor gives the runner, and its ledgers stay where they are; or why it made no plan. A plan that
    takes longer than PLAN_SECONDS is ended, its process group's processes printed first.
    """
    git("checkout", "--quiet", "--force", "-B", BRANCH, at)
    env = {name: value for name, value in os.environ.items() if not CI_VARIABLES.match(name)}
    command = ["php", str(gate / "bin/mutation-gate"), "plan", "--selection-only", f"--changed-since={since}",
               f"--coverage={coverage}"]
    started = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, env=env,
                               start_new_session=True)
    try:
        said, _ = started.communicate(timeout=PLAN_SECONDS)
    except subprocess.TimeoutExpired:
        print(stalled(started.pid), file=sys.stderr, flush=True)
        os.killpg(started.pid, signal.SIGKILL)
        started.communicate()
        return {"error": [f"the plan did not end within {PLAN_SECONDS} s"]}
    if started.returncode != 0:
        return {"error": said.strip().splitlines()[-1:] or ["it printed nothing"]}
    return json.loads(Path(".mutation-gate/plan.json").read_text(encoding="utf-8"))


def stalled(group: int) -> str:
    """The processes of a plan's process group, as `ps` lists them, for a plan that did not end in time."""
    listed = subprocess.run(["ps", "-eo", "pid,ppid,pgid,stat,etime,command"], capture_output=True, text=True).stdout
    lines = listed.splitlines()
    held = [line for line in lines[1:] if line.split()[2:3] == [str(group)]]
    return "\n".join([f"The plan did not end within {PLAN_SECONDS} s; its processes:", *lines[:1], *held])


def cloned(root: Path, work: Path) -> Path:
    """A clone of the checkout at ROOT under WORK, with the directories TAKEN copied in, to plan the replays in."""
    clone = work / "replay"
    git("clone", "--quiet", "--local", "--no-checkout", str(root), str(clone))
    for taken in TAKEN:
        if (root / taken).is_dir():
            shutil.copytree(root / taken, clone / taken, symlinks=True)
    return clone


def said(label: str, started: float, plan: dict) -> None:
    """One line on standard error as a plan ends: what it planned, how long it took, and how many units it selected."""
    units = "no plan" if "error" in plan else f"{len(reached(plan))} units"
    print(f"{label}: {time.monotonic() - started:.0f}s, {units}", file=sys.stderr, flush=True)


def replayed(gate: Path, head: str, commit: str, coverage: Path) -> tuple[list[str], dict]:
    """The paths a pull request changed, and the plan of that change on top of HEAD."""
    parent = git("rev-parse", f"{commit}^1").strip()
    paths = [path for path in git("diff", "--name-only", "--no-renames", parent, commit).splitlines() if path]
    before = git("commit-tree", tree_with(head, parent, paths), "-p", head, "-m", f"before {commit}", env=IDENTITY).strip()
    after = git("commit-tree", tree_with(head, commit, paths), "-p", before, "-m", f"after {commit}", env=IDENTITY).strip()
    return paths, planned(gate, after, before, coverage)


def main(argv: list[str]) -> int:
    if len(argv) != 7 or argv[1:6:2] != ["--map", "--findings", "--table"] or not argv[0].isdigit():
        print(__doc__, file=sys.stderr)
        return 2
    count, coverage = int(argv[0]), Path(argv[2]).resolve()
    findings_file, table_file = Path(argv[4]).resolve(), Path(argv[6]).resolve()
    root = Path.cwd()
    head = git("rev-parse", "HEAD").strip()
    rows, skipped, findings = [], [], []
    with tempfile.TemporaryDirectory() as work:
        os.chdir(cloned(root, Path(work)))
        try:
            started = time.monotonic()
            baseline = planned(root, head, head, coverage)
            said("HEAD", started, baseline)
            if "error" in baseline:
                print(*baseline["error"], file=sys.stderr)
                return 1
            always = reached(baseline)
            for number, commit in merged(git("log", "--first-parent", "--format=%H%x09%s", head), count):
                started = time.monotonic()
                paths, plan = replayed(root, head, commit, coverage)
                said(f"#{number}", started, plan)
                if "error" in plan:
                    skipped.append({"number": number, "why": " ".join(plan["error"])})
                    continue
                beyond = reached(plan) - always
                findings.extend(judge(number, paths, plan, beyond))
                rows.append({"number": number, "paths": len(paths), "units": len(beyond), "widening": widening(plan)})
        finally:
            os.chdir(root)
    findings_file.parent.mkdir(parents=True, exist_ok=True)
    findings_file.write_text(json.dumps(findings, indent=1) + "\n", encoding="utf-8")
    table_file.write_text(table(rows, skipped), encoding="utf-8")
    print(table(rows, skipped))
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
