#!/usr/bin/env python3
"""The index of .docs/decisions/README.md, rendered from the ADR files.

Usage:
  decisions_index.py write [<decisions directory>]
  decisions_index.py check [<decisions directory>]

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

`write` writes the table between the README's two markers; `check` exits 1,
saying what to run, where the committed table is not the one the files give.
`test_decisions_index.py` runs the check against the repository.

Two halves: `adrs`, `changes` and `table` decide, and `main` reads and
writes the files.
"""
import re
import sys
from pathlib import Path

# The README's table stands between these lines.
START = "<!-- index:start -->"
END = "<!-- index:end -->"

# Where the decisions are, from the repository's root.
DECISIONS = Path(".docs/decisions")

# The command that rewrites the table, as `check` says it.
WRITE = "python3 .github/scripts/decisions_index.py write"

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


def table(found: list[dict]) -> str:
    """The index, its markers included."""
    lines = [START, "", "| # | Decision | Status |", "|---|----------|--------|"]
    for adr in found:
        changed = changes(found, adr["number"])
        status = f"{adr['status']}; {changed}" if changed else adr["status"]
        lines.append(f"| [{adr['number']}]({adr['file']}) | {adr['title']} | {status} |")
    return "\n".join([*lines, "", END])


def rendered(readme: str, section: str) -> str:
    """The README with its table replaced."""
    before, rest = readme.split(START, 1)
    _, after = rest.split(END, 1)
    return before + section + after


def section_of(readme: str) -> str:
    """The README's table as it stands, its markers included."""
    return START + readme.split(START, 1)[1].split(END, 1)[0] + END


def read(directory: Path) -> tuple[str, str]:
    """The README as it stands, and the table its directory's ADR files give."""
    files = {path.name: path.read_text(encoding="utf-8") for path in directory.glob("*.md")}
    return (directory / "README.md").read_text(encoding="utf-8"), table(adrs(files))


def main(argv: list[str]) -> int:
    if argv[:1] not in (["write"], ["check"]) or len(argv) > 2:
        print(__doc__, file=sys.stderr)
        return 2
    directory = Path(argv[1]) if len(argv) == 2 else DECISIONS
    try:
        readme, section = read(directory)
    except ValueError as refused:
        print(refused, file=sys.stderr)
        return 1
    if argv[0] == "write":
        (directory / "README.md").write_text(rendered(readme, section), encoding="utf-8")
        return 0
    if section_of(readme) != section:
        print(f"{directory / 'README.md'}'s index is not the one its ADRs give. Run: {WRITE}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
