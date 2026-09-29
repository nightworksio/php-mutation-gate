#!/usr/bin/env python3
"""Every commit subject in base..head, and the pull request title, is conventional.

Usage: commit_lint.py <base_sha> <head_sha> [--title <pull request title>]

The title is checked because a squash merge makes it the subject of the commit
that lands on main.

Two halves: `_read` asks git, and `unconventional` decides from what git said.
"""
import re
import subprocess
import sys

_REF = re.compile(r"\A[0-9A-Za-z._/-]{1,255}\Z")

TYPES = "feat|fix|docs|refactor|test|chore|ci|perf|build|style|revert"

# A type, an optional lowercase scope, an optional `!` for a breaking change,
# then a colon, a space, and something to say.
SUBJECT = re.compile(rf"^(?:{TYPES})(?:\([a-z0-9.\-]+\))?!?: .+")

# One record per line: the sha and the subject, NUL-separated.
FORMAT = "%H%x00%s"


def conventional(subject: str) -> bool:
    """Whether a subject is conventional. A merge and a revert are git's words."""
    return subject.startswith(("Merge ", "Revert ")) or SUBJECT.match(subject) is not None


def unconventional(out: str) -> list[str]:
    """Every commit in what git said whose subject is not conventional."""
    bad = []
    for line in filter(None, out.splitlines()):
        sha, _, subject = line.partition("\x00")
        if not conventional(subject):
            bad.append(f"{sha[:8]} {subject}")
    return bad


def _read(base: str, head: str) -> str:
    return subprocess.run(
        ["git", "log", f"--format={FORMAT}", f"{base}..{head}"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout


def main(argv: list[str]) -> int:
    base, head = argv[1], argv[2]
    if not (_REF.match(base) and _REF.match(head)):
        print("::error::commit_lint: base and head must be valid git refs")
        return 1

    bad = unconventional(_read(base, head))

    if "--title" in argv[3:]:
        title = argv[argv.index("--title") + 1]
        if not conventional(title):
            bad.append(f"the pull request title: {title}")

    if bad:
        print("::error::commit subjects must be conventional (type(scope): subject):")
        for subject in bad:
            print(f"  {subject}")
        print(f"\n  types: {TYPES.replace('|', ', ')}")
        print("  e.g.  feat(cli): refuse a config with no tree")
        return 1
    print("commitlint: every subject is conventional")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
