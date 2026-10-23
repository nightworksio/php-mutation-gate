#!/usr/bin/env python3
"""Every test file the gate's mutants may run, each run alone on the unmutated code.

Usage: alone.py <checkout> --findings <file>

Run by `test files alone`. A mutant's own run loads only the test files its
covering tests need (ADR-0004), and a kill those files cannot
vouch for runs again with every test file, which costs a whole suite's start
for each. A test file that fails alone, because it leans on what another
file declares or leaves behind, makes every narrowed kill of its tests
doubtful. So each test file of the suites that judge and of the Process
suite, which holds, is run alone in a Pest of its own, several side by side;
the findings file names each that failed, with the end of what it printed
(ADR-0019).

Two halves: `test_files` and `judge` decide from paths and text alone, and
`main` runs Pest.
"""
import json
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

# The directories whose test files a mutant's narrowed run can load, from the checkout.
DIRECTORIES = ("tests/Unit", "tests/Process", "plugins/*/tests")

# What a file of test cases ends in, as phpunit.xml's suites name them.
SUFFIX = "Test.php"

# The end of a run's output a finding keeps.
TAIL = 1500


def test_files(root: Path) -> list[str]:
    """Every test file under the directories, from the checkout's root, in byte order."""
    found = {
        path.relative_to(root).as_posix()
        for pattern in DIRECTORIES
        for directory in root.glob(pattern)
        for path in directory.rglob(f"*{SUFFIX}")
    }
    return sorted(found, key=lambda path: path.encode())


def judge(ran: dict[str, tuple[int, str]]) -> list[dict]:
    """A finding for each test file whose run alone did not pass, in byte order."""
    return [
        {"path": path, "line": 0, "rule": "fails-alone", "detail": output[-TAIL:]}
        for path, (code, output) in sorted(ran.items(), key=lambda item: item[0].encode())
        if code != 0
    ]


def run(root: Path, path: str) -> tuple[int, str]:
    """What Pest exited with and printed, running this one file."""
    done = subprocess.run(
        ["php", "vendor/bin/pest", "--colors=never", path],
        cwd=root,
        capture_output=True,
        text=True,
        check=False,
        timeout=600,
    )
    return done.returncode, done.stdout + done.stderr


def main(argv: list[str]) -> int:
    if len(argv) != 4 or argv[2] != "--findings":
        print(__doc__, file=sys.stderr)
        return 2
    root, findings = Path(argv[1]).resolve(), Path(argv[3])
    files = test_files(root)
    with ThreadPoolExecutor(max_workers=os.cpu_count() or 1) as pool:
        ran = dict(zip(files, pool.map(lambda path: run(root, path), files)))
    found = judge(ran)
    findings.parent.mkdir(parents=True, exist_ok=True)
    findings.write_text(json.dumps(found, indent=2) + "\n", encoding="utf-8")
    print(f"Ran {len(files)} test files alone; {len(found)} failed.")
    for item in found:
        print(f"::error file={item['path']}::{item['path']} fails when it runs alone.")
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
