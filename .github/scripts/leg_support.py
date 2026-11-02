#!/usr/bin/env python3
"""Each runner contract leg's fixture, and the test support its tests use, from legs.json.

Usage: leg_support.py fixture <runner>    print the leg's fixture as `name=value` lines
       leg_support.py check <runner>      fail where legs.json lists other support than the leg's tests use
       leg_support.py write               list each leg's support in legs.json again

legs.json holds each runner's leg of `runner contract` (ci.yml): its fixture's
directory, the filter and the contracts its Contract run selects, how it
reaches its lowest releases, the packages its install shows, and the test
support its tests use. scope.py runs a leg where that support changed.

`check` runs in each leg, so a list that no longer holds what the leg's tests
use fails the leg that reads it. It asks Pest which tests the leg's run
selects (`--list-tests`, under the leg's filter and contracts), and reads the
support those test files and the Contract suite's helpers name, and the
support that support names, transitively. A file names a support file where it
names the class that file holds, as a word, so the reading errs towards
running a leg.

Two halves: `support_of`, `listed_files` and `differences` decide from text,
and `main` runs Pest and reads and writes the files.
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LEGS = Path(__file__).resolve().with_name("legs.json")
SUPPORT = "tests/Support/"
CONTRACT = "tests/Contract/"

# The fixture's keys a leg's steps read, in the order the step prints them.
FIXTURE = ("dir", "filter", "contracts", "analysers", "lowest", "packages")

# A test Pest lists, by its class, which names its file under tests/.
LISTED = re.compile(r"^ - P\\Tests\\((?:[A-Za-z0-9_]+\\)*[A-Za-z0-9_]+Test)::", re.M)


def listed_files(listing: str) -> list[str]:
    """The test files a `--list-tests` listing holds, as paths from the root."""
    return sorted({"tests/" + name.replace("\\", "/") + ".php" for name in LISTED.findall(listing)})


def support_of(texts: list[str], support: dict[str, str]) -> list[str]:
    """The support files these texts name, and those that support names, transitively, by path."""
    names = {path: re.compile(rf"\b{re.escape(Path(path).stem)}\b") for path in support}
    found = {path for path, name in names.items() if any(name.search(text) for text in texts)}
    pending = list(found)
    while pending:
        text = support[pending.pop()]
        for path, name in names.items():
            if path not in found and name.search(text):
                found.add(path)
                pending.append(path)
    return sorted(found)


def differences(listed: list[str], used: list[str]) -> list[str]:
    """What a leg's list misses and holds beyond what its tests use, one line each."""
    return [f"missing {path}" for path in used if path not in listed] + [
        f"not used {path}" for path in listed if path not in used
    ]


def legs() -> dict[str, dict]:
    return json.loads(LEGS.read_text(encoding="utf-8"))


def read_support() -> dict[str, str]:
    return {path.relative_to(ROOT).as_posix(): path.read_text(encoding="utf-8") for path in (ROOT / SUPPORT).rglob("*.php")}


def helpers() -> list[str]:
    """The Contract suite's files that are no test and no fixture: each suite directory's own PHP beside its tests."""
    return sorted(
        path.relative_to(ROOT).as_posix()
        for path in (ROOT / CONTRACT).glob("*/*.php")
        if not path.name.endswith("Test.php")
    )


def used_by(leg: dict) -> list[str]:
    """The support a leg's tests use, from the tests Pest says its run selects."""
    command = ["php", "vendor/pestphp/pest/bin/pest", "--testsuite=Contract", "--list-tests"]
    if leg["filter"] != "":
        command.append(f"--filter={leg['filter']}")
    environment = {**os.environ, "RUNNER_CONTRACTS": leg["contracts"], "ANALYSER_CONTRACTS": leg["analysers"]}
    listing = subprocess.run(command, capture_output=True, text=True, check=True, cwd=ROOT, env=environment).stdout
    files = listed_files(listing) + helpers()
    return support_of([(ROOT / path).read_text(encoding="utf-8") for path in files], read_support())


def main() -> int:
    if len(sys.argv) == 3 and sys.argv[1] == "fixture":
        leg = legs().get(sys.argv[2])
        if leg is None:
            print(f"legs.json names no leg for the runner {sys.argv[2]}.", file=sys.stderr)
            return 1
        for key in FIXTURE:
            print(f"{key}={leg[key]}")
        return 0
    if len(sys.argv) == 3 and sys.argv[1] == "check":
        leg = legs()[sys.argv[2]]
        found = differences(leg["support"], used_by(leg))
        for line in found:
            print(f"::error title=leg support::{sys.argv[2]}: {line}")
        if found:
            print("Run `python3 .github/scripts/leg_support.py write` and commit legs.json.")
        return 1 if found else 0
    if len(sys.argv) == 2 and sys.argv[1] == "write":
        table = legs()
        for leg in table.values():
            leg["support"] = used_by(leg)
        LEGS.write_text(json.dumps(table, indent=2) + "\n", encoding="utf-8")
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
