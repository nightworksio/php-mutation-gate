#!/usr/bin/env python3
"""Every test of the scripts in this directory, and what failed as the job's evidence.

Usage: unit.py [<findings file>]

It runs `unittest` discovery over .github/scripts, as `python3 -m unittest
discover --start-directory .github/scripts` does, and writes each failed or
broken test to the findings file, at the line of its own file that failed
(ADR-0019, decision 5).

Two halves: `findings` decides from unittest's result, and `main` runs it.
"""
import json
import re
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent


def where(file: Path, trace: str, root: Path) -> tuple[str, int]:
    """A test's own file, spelt from the repository root, and the last line of it the trace passes."""
    frame = re.compile(rf'^\s*File "{re.escape(str(file))}", line (\d+)', re.MULTILINE)
    lines = frame.findall(trace)
    path = file.relative_to(root).as_posix() if root in file.parents else file.name
    return path, int(lines[-1]) if lines else 0


def findings(result: unittest.TestResult) -> list[dict]:
    """Each failed or broken test, where it failed."""
    found = []
    for rule, listed in (("failure", result.failures), ("error", result.errors)):
        for test, trace in listed:
            module = sys.modules.get(type(test).__module__)
            path, line = where(Path(getattr(module, "__file__", "") or ""), trace, HERE.parents[1])
            found.append({"path": path, "line": line, "rule": rule, "detail": test.id()})
    return found


def main(argv: list[str]) -> int:
    suite = unittest.defaultTestLoader.discover(str(HERE))
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if len(argv) > 1:
        Path(argv[1]).parent.mkdir(parents=True, exist_ok=True)
        Path(argv[1]).write_text(json.dumps(findings(result)), encoding="utf-8")
    return 0 if result.wasSuccessful() else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
