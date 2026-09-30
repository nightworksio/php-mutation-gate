#!/usr/bin/env python3
"""What a failed gate checks, and how to reproduce and fix it, from .github/gates.json.

Usage: gate_summary.py <gate>

Run as a job's last step, on failure only (ADR-0019, decision 7). It writes the
gate's entry to the step summary and as an error annotation, which needs no
privilege, so a fork's pull request reads it too.

Two halves: `summary` and `annotation` decide the words, and `main` reads the
table and writes them.
"""
import json
import os
import sys
from pathlib import Path

TROUBLESHOOTING = "https://github.com/nightworksio/php-mutation-gate/blob/main/.docs/guide/troubleshooting.md#{}"


def summary(entry: dict) -> str:
    """The step summary's section for a failed gate."""
    lines = [
        f"### `{entry['check']}` failed",
        "",
        entry["checks"],
        "",
        "Reproduce it with:",
        "",
        "```sh",
        entry["reproduce"],
        "```",
    ]
    if entry.get("fix"):
        lines += ["", "Fix it with:", "", "```sh", entry["fix"], "```"]
    if entry.get("troubleshooting"):
        lines += ["", f"See [{entry['troubleshooting']}]({TROUBLESHOOTING.format(entry['troubleshooting'])})."]
    return "\n".join(lines) + "\n"


def annotation(entry: dict) -> str:
    """The error annotation for a failed gate: what it checks, and the command."""
    fix = f" Fix it with: {entry['fix']}" if entry.get("fix") else ""
    words = f"{entry['checks']} Reproduce it with: {entry['reproduce']}.{fix}"
    title = entry["check"].replace("%", "%25").replace(":", "%3A").replace(",", "%2C")
    return f"::error title={title} failed::{words.replace('%', '%25').replace(chr(10), '%0A')}"


def main(argv: list[str]) -> int:
    entry = json.loads(Path(".github/gates.json").read_text(encoding="utf-8"))["gates"][argv[1]]
    target = os.environ.get("GITHUB_STEP_SUMMARY")
    if target:
        with open(target, "a", encoding="utf-8") as out:
            out.write(summary(entry))
    print(annotation(entry))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
