#!/usr/bin/env python3
"""A pull request's title and body: what must hold, and what would help.

Usage: pr_text.py <base_sha> <head_sha> [--findings <file>]

Reads the title and body from PR_TITLE and PR_BODY, and the changed paths
from git. It fails where the body names a `Spec:` that is no decision in
.docs/decisions, or where a breaking title (`feat!:`) has no *Migration*
section, because the squash merge makes the body main's commit message
(ADR-0019, decision 12). It never fails for its suggestions: a conventional
title built from the changed paths where the title is not conventional, and
the decisions .github/spec-map.json maps the changed paths to where the body
has no `Spec:` line. With --findings, both are written as the job's evidence.

Two halves: the functions above `main` decide from text alone, and `main`
asks git and the environment.
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

import commit_lint

_REF = re.compile(r"\A[0-9A-Za-z._/-]{1,255}\Z")

# A `Spec:` line of the body, naming one decision or several.
SPEC = re.compile(r"^Spec:[ \t]*(.+?)[ \t]*$", re.MULTILINE)

# A decision's number, as a `Spec:` line and .docs/decisions write it.
NUMBER = re.compile(r"\b(\d{4})\b")

# A breaking change's title: `feat!:` or `feat(cli)!:`.
BREAKING = re.compile(r"^[a-z]+(?:\([a-z0-9.\-]+\))?!: ")

# The section a breaking change's body carries.
MIGRATION = re.compile(r"^#{1,6}[ \t]+Migration\b", re.MULTILINE | re.IGNORECASE)

# A change that touches only paths under one of these has this type.
TYPES_BY_PATH = (("docs", (".docs/",)), ("test", ("tests/",)), ("ci", (".github/",)))

# The layers whose next directory names the scope: src/Adapter/Pest -> pest.
LAYERED = ("Adapter", "Core")


def spec_numbers(body: str) -> list[str]:
    """Every decision number the body's `Spec:` lines name, in order."""
    return [number for line in SPEC.findall(body) for number in NUMBER.findall(line)]


def unknown_specs(numbers: list[str], decisions: set[str]) -> list[str]:
    """The numbers no decision in .docs/decisions carries."""
    return [number for number in numbers if number not in decisions]


def misses_migration(title: str, body: str) -> bool:
    """Whether a breaking title comes with no *Migration* section."""
    return BREAKING.match(title) is not None and MIGRATION.search(body) is None


def scope_of(paths: list[str]) -> str:
    """The one scope the changed source files share, or none where they share none."""
    scopes = set()
    for path in paths:
        parts = path.split("/")
        if parts[0] != "src" or len(parts) < 3:
            continue
        named = parts[2] if parts[1] in LAYERED and len(parts) > 3 else parts[1]
        scopes.add(re.sub(r"\.php$", "", named).lower())
    return scopes.pop() if len(scopes) == 1 else ""


def type_of(paths: list[str]) -> str:
    """The type every changed path shares, or none where they share none."""
    for kind, prefixes in TYPES_BY_PATH:
        if paths and all(path.startswith(prefixes) for path in paths):
            return kind
    return ""


def suggested_title(paths: list[str]) -> str:
    """A conventional title's head, from the changed paths: `docs: `, `<type>(pest): `."""
    kind = type_of(paths) or "<type>"
    scope = scope_of(paths)
    return f"{kind}({scope}): " if scope else f"{kind}: "


def glob_pattern(glob: str) -> re.Pattern:
    """A glob as the config reads one: `*` and `?` within a directory, `**` across any number."""
    pieces = re.findall(r"\*\*/|\*\*|\*|\?|[^*?]+", glob)
    wildcards = {"**/": "(?:.*/)?", "**": ".*", "*": "[^/]*", "?": "[^/]"}
    return re.compile("^" + "".join(wildcards.get(piece, re.escape(piece)) for piece in pieces) + "$")


def mapped_specs(paths: list[str], spec_map: dict[str, list[str]]) -> list[str]:
    """The decisions .github/spec-map.json maps any changed path to, in number order."""
    found = set()
    for glob, numbers in spec_map.items():
        pattern = glob_pattern(glob)
        if any(pattern.match(path) for path in paths):
            found.update(numbers)
    return sorted(found)


def findings(title: str, body: str, paths: list[str], decisions: set[str], spec_map: dict[str, list[str]]) -> list[dict]:
    """What must hold and what would help, as the job's evidence."""
    found = [
        {"path": "", "line": 0, "rule": "unknown-spec", "detail": number}
        for number in unknown_specs(spec_numbers(body), decisions)
    ]
    if misses_migration(title, body):
        found.append({"path": "", "line": 0, "rule": "missing-migration", "detail": "title"})
    if not commit_lint.conventional(title):
        found.append({"path": "", "line": 0, "rule": "suggest-title", "detail": suggested_title(paths)})
    suggested = mapped_specs(paths, spec_map)
    if not spec_numbers(body) and suggested:
        found.append({"path": "", "line": 0, "rule": "suggest-spec", "detail": ", ".join(suggested)})
    return found


def failing(found: list[dict]) -> list[dict]:
    """The findings that fail the job: the suggestions only help."""
    return [item for item in found if not item["rule"].startswith("suggest-")]


def _changed(base: str, head: str) -> list[str]:
    return subprocess.run(
        ["git", "diff", "--name-only", "-z", f"{base}...{head}"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout.split("\x00")[:-1]


def main(argv: list[str]) -> int:
    base, head = argv[1], argv[2]
    if not (_REF.match(base) and _REF.match(head)):
        print("::error::pr_text: base and head must be valid git refs")
        return 1

    decisions = {name[:4] for name in os.listdir(".docs/decisions") if re.match(r"^\d{4}-", name)}
    spec_map = json.loads(Path(".github/spec-map.json").read_text(encoding="utf-8"))["globs"]
    found = findings(os.environ.get("PR_TITLE", ""), os.environ.get("PR_BODY", ""), _changed(base, head), decisions, spec_map)

    if "--findings" in argv[3:]:
        Path(argv[argv.index("--findings") + 1]).write_text(json.dumps(found), encoding="utf-8")

    for item in found:
        print(f"{item['rule']}: {item['detail']}")

    bad = failing(found)
    for item in bad:
        print(f"::error::{item['rule']}: {item['detail']}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
