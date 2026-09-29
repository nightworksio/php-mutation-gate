#!/usr/bin/env python3
"""Every commit in base..head carries a Signed-off-by matching its author.

Usage: dco_check.py <base_sha> <head_sha>

Two halves: `_read` asks git, and `unsigned` decides from what git said.
"""
import re
import subprocess
import sys

_REF = re.compile(r"\A[0-9A-Za-z._/-]{1,255}\Z")

# One record per commit, fields NUL-separated and records ending in \x01, so a
# body's newlines cannot be mistaken for the end of a commit.
FORMAT = "%H%x00%an%x00%ae%x00%P%x00%b%x01"

SIGNED_OFF = re.compile(r"^Signed-off-by:[^<]*<([^>]+)>\s*$", re.MULTILINE)


def is_bot(name: str, email: str) -> bool:
    """Whether this commit was authored by something with nobody behind it."""
    return name.endswith("[bot]") or "[bot]@" in email or email == "noreply@github.com"


def unsigned(out: str) -> list[str]:
    """Every commit in what git said that carries no sign-off of its author's.

    A merge commit and a bot's commit are exempt: GitHub writes them, and there
    is nobody to attest. The match is on the email, case-insensitively.
    """
    problems = []
    for rec in filter(None, out.split("\x01\n")):
        parts = rec.strip("\x01\n").split("\x00")
        if len(parts) < 5:
            continue
        sha, an, ae, parents, body = parts[0], parts[1], parts[2], parts[3], parts[4]
        if " " in parents.strip() or is_bot(an, ae):
            continue
        signoffs = SIGNED_OFF.findall(body)
        if not any(email.lower() == ae.lower() for email in signoffs):
            problems.append(f"{sha[:8]} by {an} <{ae}> lacks a matching Signed-off-by")
    return problems


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
        print("::error::dco_check: base and head must be valid git refs")
        return 1

    problems = unsigned(_read(base, head))

    if problems:
        print("::error::DCO sign-off missing:")
        for problem in problems:
            print(f"  {problem}")
        print("\nAdd it with: git commit -s")
        return 1
    print("dco: every commit is signed off")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
