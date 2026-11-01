#!/usr/bin/env python3
"""The benchmark's published numbers: every job's result in one file, and the README's table rendered from it.

Usage:
  bench_publish.py collect <artifacts directory> <version> <run URL> <result file>
  bench_publish.py readme <results directory> <README>

`collect` is run by `bench.yml` once every project's jobs have ended, on
the directory it downloaded their result files into: `<project>.json` from
its arms, `<project>-sharded.json` from its sharded arm and
`<project>-scenarios.json` from its scenarios. It writes them into one
file, `bench/results/<version>.json`, with the run that measured them.

`readme` writes the README's table, between its two markers, from the newest
result file in the directory, by the date it holds. The numbers reach the
README through a pull request that commits both, and
`test_bench_publish.py` fails when the README's table is not the one its
newest result file renders (ADR-0017).

Two halves: `combined` and `table` decide, and `main` reads and writes the
files.
"""
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

# The README's table stands between these lines.
START = "<!-- bench:start -->"
END = "<!-- bench:end -->"

# What the table says while no result file is committed.
NONE = "No benchmark result is committed. `bench.yml` measures one, and a pull request brings it here."

# Each project's result files, by what measured them.
KINDS = {"": "cold", "-sharded": "sharded", "-scenarios": "scenarios"}


def combined(results: dict[str, dict], version: str, run: str, date: str) -> dict:
    """Every project's results in one file, keyed by the project's file name, with the run that measured them."""
    projects: dict[str, dict] = {}
    for name, result in sorted(results.items()):
        for suffix, kind in KINDS.items():
            if suffix and name.endswith(suffix):
                projects.setdefault(name[: -len(suffix)], {})[kind] = result
                break
        else:
            projects.setdefault(name, {})["cold"] = result
    return {"version": version, "run": run, "date": date, "projects": projects}


def duration(seconds: float) -> str:
    """Seconds as a reader takes them in: `48s`, `3m 05s` or `1h 02m`."""
    whole = round(seconds)
    if whole < 60:
        return f"{whole}s"
    if whole < 3600:
        return f"{whole // 60}m {whole % 60:02d}s"
    return f"{whole // 3600}h {whole % 3600 // 60:02d}m"


def ranged(spread: dict) -> str:
    """A median with its range, or a dash where nothing ran."""
    if not spread:
        return "-"
    if spread["min"] == spread["max"]:
        return duration(spread["median"])
    return f"{duration(spread['median'])} ({duration(spread['min'])}–{duration(spread['max'])})"


def counted(files: dict) -> str:
    """An arm's mutants: how many it made, and how many escaped."""
    total = files.get("total")
    return f"{total['generated']}, {total['escaped']} escaped" if total else "-"


def rows(project: dict) -> list[str]:
    """A project's rows: each arm of its cold full run, its sharded arm, and each gate arm's scenarios."""
    found = []
    for name, arm in project.get("cold", {}).get("arms", {}).items():
        found.append(f"| cold full | {name} | {ranged(arm['wall'])} | - | {counted(arm['files'])} |")
    for name, arm in project.get("sharded", {}).get("arms", {}).items():
        found.append(f"| cold full | {name}, {arm['shards']} shards | {ranged(arm['wallWithSetup'])} "
                     f"| {ranged(arm['runnerWithSetup'])} | {counted(arm['files'])} |")
    for name, arm in project.get("scenarios", {}).get("arms", {}).items():
        for scenario, label in (("warm", "warm full"), ("unchanged", "unchanged re-run (trivial)")):
            if arm.get(scenario):
                found.append(f"| {label} | {name} | {ranged(arm[scenario])} | - | - |")
        replay = arm.get("replay")
        if replay and replay["pulls"]:
            found.append(f"| PR replay, {replay['judged']} of {replay['pulls']} judged | {name} "
                         f"| {duration(replay['median'])} median, {duration(replay['p90'])} p90 | - | - |")
    return found


def heading(project: dict) -> str:
    """A project's heading: its name, version and the commit it is pinned to."""
    first = next(iter(project.values()))
    return f"### {first['project']} {first['version']} at `{first['commit'][:12]}`"


def table(result: dict | None, path: str) -> str:
    """The README's table: each project's arms and scenarios, linked to the run and the raw file."""
    if result is None:
        return "\n".join([START, "", NONE, "", END])
    machines = {each.get("machine", {}).get("cpu", "") for project in result["projects"].values()
                for each in project.values()}
    lines = [
        START,
        "",
        f"Measured by [this run of `bench.yml`]({result['run']}) of the gate at {result['version']}, on "
        f"GitHub-hosted runners ({', '.join(sorted(each for each in machines if each))}); the raw numbers "
        f"are in [{path}]({path}). Each time is the median of three rounds, with the range; the sharded "
        "arm's times include every machine's setup.",
    ]
    for project in result["projects"].values():
        lines += [
            "",
            heading(project),
            "",
            "| scenario | arm | wall time | runner time | mutants |",
            "|---|---|---:|---:|---:|",
            *rows(project),
        ]
    return "\n".join([*lines, "", END])


def newest(directory: Path) -> tuple[dict | None, str]:
    """The newest result file in the directory by the date it holds, and its path from the repository root."""
    found = [(json.loads(path.read_text(encoding="utf-8")), path) for path in sorted(directory.glob("*.json"))]
    if not found:
        return None, ""
    result, path = max(found, key=lambda each: each[0]["date"])
    return result, f"bench/results/{path.name}"


def rendered(readme: str, section: str) -> str:
    """The README with its table replaced."""
    before, rest = readme.split(START, 1)
    _, after = rest.split(END, 1)
    return before + section + after


def section_of(readme: str) -> str:
    """The README's table as it stands, its markers included."""
    return START + readme.split(START, 1)[1].split(END, 1)[0] + END


def main(argv: list[str]) -> int:
    if argv[:1] == ["collect"] and len(argv) == 5:
        artifacts, result = Path(argv[1]), Path(argv[4])
        results = {path.stem: json.loads(path.read_text(encoding="utf-8")) for path in sorted(artifacts.glob("*.json"))}
        date = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        result.parent.mkdir(parents=True, exist_ok=True)
        result.write_text(json.dumps(combined(results, argv[2], argv[3], date), indent=2) + "\n", encoding="utf-8")
        return 0
    if argv[:1] == ["readme"] and len(argv) == 3:
        readme = Path(argv[2])
        found, path = newest(Path(argv[1]))
        readme.write_text(rendered(readme.read_text(encoding="utf-8"), table(found, path)), encoding="utf-8")
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
