#!/usr/bin/env python3
"""No commit, and no pull request body, credits an AI assistant or a tool.

Usage: attribution_check.py <base_sha> <head_sha> [--body-on-stdin] [--findings <file>]

Two halves: `_read` asks git, and `attributed` decides from text. With
--findings, what it refused is also written there as the job's evidence
(ADR-0019, decision 5).

Every pattern is anchored to the start of a line with no leading whitespace, so
prose that quotes a forbidden line, indented, can name it without being refused.
.githooks/commit-msg holds the same patterns and the same names.
"""
import json
import re
import subprocess
import sys

_REF = re.compile(r"\A[0-9A-Za-z._/-]{1,255}\Z")

# One record per commit, fields NUL-separated and records ending in \x01.
FORMAT = "%H%x00%s%x00%B%x01"

ASSISTANTS = (
    "claude",
    "copilot",
    "chatgpt",
    "openai",
    "anthropic",
    "cursor",
    "devin",
    "codex",
    "gemini",
    "aider",
)

_NAMES = "|".join(ASSISTANTS)

# A co-author trailer naming one of them, or carrying one of their addresses.
TRAILER = re.compile(
    rf"^Co-authored-by:[^\n]*(?:{_NAMES})[^\n]*$",
    re.IGNORECASE | re.MULTILINE,
)

# The badge: any "Generated with" line, and a "written by", "authored with" or
# "created by" line that names one of them. An emoji may lead it; whitespace
# may not.
BADGE = re.compile(
    rf"^(?:[^\w\s]+[ \t]*)?(?:generated\s+with\b|(?:generated|written|authored|created)\s+(?:with|by)\s+[^\n]*(?:{_NAMES}))[^\n]*$",
    re.IGNORECASE | re.MULTILINE,
)


def attributed(text: str) -> list[str]:
    """Every line of this text that credits an assistant."""
    found = []
    for pattern in (TRAILER, BADGE):
        found.extend(match.strip() for match in pattern.findall(text))
    return found


def problems(out: str, body: str) -> list[str]:
    """Every commit in what git said, and the pull request body, that credits one."""
    said = []

    for rec in filter(None, out.split("\x01\n")):
        parts = rec.strip("\x01\n").split("\x00")
        if len(parts) < 3:
            continue
        sha, subject, message = parts[0], parts[1], parts[2]
        for line in attributed(message):
            said.append(f"{sha[:8]} {subject}\n      {line}")

    for line in attributed(body):
        said.append(f"the pull request\n      {line}")

    return said


def as_findings(said: list[str]) -> list[dict]:
    """What `problems` found, as evidence: a commit by its short sha, or the pull request."""
    return [
        {
            "path": "",
            "line": 0,
            "rule": "credits-an-assistant",
            "detail": "the pull request" if one.startswith("the pull request") else one.split(" ", 1)[0],
        }
        for one in said
    ]


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
        print("::error::attribution_check: base and head must be valid git refs")
        return 1

    # A pull request always carries a commit, so an empty range means the refs
    # are not the ones the run was about, and a pass would be about nothing.
    log = _read(base, head)
    if not log.strip():
        print(f"::error::attribution_check: no commit between {base} and {head}, so the record was not read")
        return 1

    body = sys.stdin.read() if "--body-on-stdin" in argv[3:] else ""
    said = problems(log, body)

    if "--findings" in argv[3:]:
        with open(argv[argv.index("--findings") + 1], "w", encoding="utf-8") as out:
            json.dump(as_findings(said), out)

    if said:
        print("::error::The record credits an assistant or a tool:")
        for one in said:
            print(f"  {one}")
        print("\nThe work is the author's own. Remove the trailer or the badge line.")
        return 1
    print("attribution: the record credits nobody it should not")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
