#!/usr/bin/env python3
"""The index of the decisions, built from the ADR files when the docs site is built (ADR-0063).

Usage:
  decisions_index.py rows [<decisions directory>]
  decisions_index.py validate [<decisions directory>]

Each row comes from one ADR's own file: its number and file name, its
`# ADR-NNNN: <title>` heading and its `**Status:**` line. What a later ADR
changes in it comes from the later ADR, which carries a line under its
Status and Date for each change it makes:

  **Amends:** [0003](0003-a-floor-only-rises.md): its lowering and truncated floors

The verb is Supersedes, Amends, Narrows or Grows. A change that holds only
in part ends in `, for <which part>`. The old ADR's cell reads "<status>;
<what> <verb-ed> by [NNNN](...)", one clause a change, the ADRs that change
one thing the same way joined by "and", the clauses in the order of the
first ADR that makes each, and a clause's verb left out where the one
before it has the same.

`rows` prints each ADR's row as JSON, its number, file, title and status
cell, which the site's build puts between the README's two markers.
`validate` exits 1, saying why, where an ADR file cannot be read as one, where
two files take one number, or where the README holds anything between its
markers, since the index is never committed. `test_decisions_index.py` runs
it against the repository.

Two halves: `adrs`, `changes`, `rows` and `problems` decide, and `main` reads
the files.
"""
import json
import re
import sys
from pathlib import Path

# The site builds the index between these lines of the README.
START = "<!-- index:start -->"
END = "<!-- index:end -->"

# Where the decisions are, from the repository's root.
DECISIONS = Path(".docs/decisions")

# The command that says what keeps the index from building, as CI runs it.
VALIDATE = "python3 .github/scripts/decisions_index.py validate"

# Each verb a change line may begin with, and how the old ADR's cell says it.
VERBS = {"Supersedes": "superseded", "Amends": "amended", "Narrows": "narrowed", "Grows": "grown"}

FILE = re.compile(r"^(\d{4})-[a-z0-9-]+\.md$")
HEADING = re.compile(r"^# ADR-(\d{4}): (.+)$", re.M)
STATUS = re.compile(r"^\*\*Status:\*\* (.+)$", re.M)
CHANGE = re.compile(r"^\*\*([A-Z][a-z]+):\*\* \[(\d{4})\]\((\d{4}-[a-z0-9-]+\.md)\): (.+)$", re.M)
QUALIFIED = re.compile(r"^(.+), (for .+)$")


def adrs(files: dict[str, str]) -> list[dict]:
    """Each ADR by its file name and text, in number order: its number, file, title, status and the changes it makes."""
    found = []
    for name in sorted(files):
        matched = FILE.match(name)
        if not matched:
            continue
        text = files[name]
        heading, status = HEADING.search(text), STATUS.search(text)
        if not heading or heading.group(1) != matched.group(1):
            raise ValueError(f"{name} has no `# ADR-{matched.group(1)}: <title>` heading.")
        if not status:
            raise ValueError(f"{name} has no `**Status:**` line.")
        made = []
        for verb, number, target, what in CHANGE.findall(text):
            if verb not in VERBS:
                raise ValueError(f"{name} {verb.lower()} ADR-{number}; a change is one of {', '.join(VERBS)}.")
            if not target.startswith(f"{number}-") or target not in files:
                raise ValueError(f"{name} links ADR-{number} as {target}, which is not its file.")
            qualified = QUALIFIED.match(what)
            made.append({"verb": verb, "of": number, "what": qualified.group(1) if qualified else what,
                         "for": qualified.group(2) if qualified else ""})
        found.append({"number": matched.group(1), "file": name, "title": heading.group(2).strip(),
                      "status": status.group(1).strip(), "changes": made})
    return found


def changes(found: list[dict], number: str) -> str:
    """What later ADRs change in one ADR, as its cell says it after its status; empty where none does."""
    groups: list[dict] = []
    for adr in found:
        for change in adr["changes"]:
            if change["of"] != number:
                continue
            key = (change["what"], change["verb"], change["for"])
            group = next((each for each in groups if each["key"] == key), None)
            if group is None:
                groups.append({"key": key, "by": [adr]})
            elif adr not in group["by"]:
                group["by"].append(adr)
    clauses, previous = [], None
    for group in groups:
        what, verb, which = group["key"]
        links = [f"[{each['number']}]({each['file']})" for each in group["by"]]
        by = links[0] if len(links) == 1 else f"{', '.join(links[:-1])} and {links[-1]}"
        said = f"{what} by {by}" if verb == previous else f"{what} {VERBS[verb]} by {by}"
        clauses.append(f"{said} {which}" if which else said)
        previous = verb
    return ", and ".join(clauses)


def rows(found: list[dict]) -> list[dict]:
    """Each ADR's row of the index: its number, file and title, and its status with what later ADRs change in it."""
    found_rows = []
    for adr in found:
        changed = changes(found, adr["number"])
        status = f"{adr['status']}; {changed}" if changed else adr["status"]
        found_rows.append({"number": adr["number"], "file": adr["file"], "title": adr["title"], "status": status})
    return found_rows


def problems(files: dict[str, str]) -> list[str]:
    """What keeps the decisions from building their index: an ADR that cannot be read, a number two files take, or a committed index."""
    try:
        adrs(files)
    except ValueError as refused:
        return [str(refused)]
    numbers: dict[str, list[str]] = {}
    for name in sorted(files):
        matched = FILE.match(name)
        if matched:
            numbers.setdefault(matched.group(1), []).append(name)
    found = [f"ADR-{number} is taken by {' and '.join(names)}; give the newer one the next free number."
             for number, names in numbers.items() if len(names) > 1]
    readme = files.get("README.md", "")
    if START not in readme or END not in readme:
        found.append(f"README.md has no {START} and {END} markers for the site to build the index between.")
    elif readme.split(START, 1)[1].split(END, 1)[0].strip() != "":
        found.append("README.md holds an index between its markers; the site builds it, so leave nothing there.")
    return found


def read(directory: Path) -> dict[str, str]:
    """Each Markdown file of the decisions, by its name."""
    return {path.name: path.read_text(encoding="utf-8") for path in directory.glob("*.md")}


def main(argv: list[str]) -> int:
    if argv[:1] not in (["rows"], ["validate"]) or len(argv) > 2:
        print(__doc__, file=sys.stderr)
        return 2
    directory = Path(argv[1]) if len(argv) == 2 else DECISIONS
    files = read(directory)
    found = problems(files)
    for problem in found:
        print(problem, file=sys.stderr)
    if found:
        return 1
    if argv[0] == "rows":
        print(json.dumps(rows(adrs(files)), indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
