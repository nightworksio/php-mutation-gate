#!/usr/bin/env python3
"""Every test of the scripts in this directory, every line of the bot's scripts covered, and what failed as the job's evidence.

Usage: unit.py [<findings file>]

It runs `unittest` discovery over .github/scripts, as `python3 -m unittest
discover --start-directory .github/scripts` does, while `sys.monitoring`
records each line that runs. Every line of the scripts COVERED names must run,
as every line of `src` must (ADR-0011); the lines under a script's
`if __name__ == "__main__":` are its entry, which the tests call by `main`.
Each failed or broken test, and each line no test ran, is written to the
findings file, at its line (ADR-0019).

Two halves: `findings`, `executable` and `uncovered` decide from what they are
given, and `main` runs the suite.
"""
import ast
import json
import re
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent

# The scripts held to every line covered: the bot's, which hold a write token.
COVERED = ("bot_*.py", "github_api.py")


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


def executable(source: str, name: str) -> set[int]:
    """The lines of a script that run code, less its `__main__` entry."""
    lines: set[int] = set()
    pending = [compile(source, name, "exec")]
    while pending:
        code = pending.pop()
        lines.update(line for _, _, line in code.co_lines() if line)
        pending.extend(constant for constant in code.co_consts if hasattr(constant, "co_lines"))
    for node in ast.parse(source).body:
        if isinstance(node, ast.If) and ast.unparse(node.test) in ("__name__ == '__main__'", '__name__ == "__main__"'):
            lines -= set(range(node.body[0].lineno, (node.end_lineno or node.lineno) + 1))
    return lines


def uncovered(sources: dict[Path, str], ran: dict[str, set[int]], root: Path) -> list[dict]:
    """Each line of a covered script that no test ran."""
    found = []
    for path, source in sorted(sources.items()):
        missed = sorted(executable(source, str(path)) - ran.get(str(path), set()))
        name = path.relative_to(root).as_posix() if root in path.parents else path.name
        found.extend({"path": name, "line": line, "rule": "uncovered", "detail": f"{name}:{line}"} for line in missed)
    return found


def _monitor(files: set[str]) -> dict[str, set[int]]:
    ran: dict[str, set[int]] = {name: set() for name in files}
    monitoring = sys.monitoring
    tool = monitoring.COVERAGE_ID

    def line(code, number):
        if code.co_filename in ran:
            ran[code.co_filename].add(number)
        return monitoring.DISABLE

    monitoring.use_tool_id(tool, "unit")
    monitoring.register_callback(tool, monitoring.events.LINE, line)
    monitoring.set_events(tool, monitoring.events.LINE)
    return ran


def main(argv: list[str]) -> int:
    covered = {path: path.read_text(encoding="utf-8") for pattern in COVERED for path in HERE.glob(pattern)}
    ran = _monitor({str(path) for path in covered})
    suite = unittest.defaultTestLoader.discover(str(HERE))
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    sys.monitoring.set_events(sys.monitoring.COVERAGE_ID, 0)
    missed = uncovered(covered, ran, HERE.parents[1])
    for item in missed:
        print(f"::error file={item['path']},line={item['line']}::No test runs this line.")
    if len(argv) > 1:
        Path(argv[1]).parent.mkdir(parents=True, exist_ok=True)
        Path(argv[1]).write_text(json.dumps(findings(result) + missed), encoding="utf-8")
    return 0 if result.wasSuccessful() and not missed else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
