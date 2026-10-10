#!/usr/bin/env python3
"""Which of ci.yml's gates a run is worth, from the paths it changed.

Usage: scope.py <event> < changed paths, one per line
       scope.py <event> --no-base

Run by `what changed`. It prints the job's outputs, one `name=value` per line:

- `code`: whether any PHP gate runs. Anything that is not documentation is
  code, so a new kind of file runs the gates.
- `warm`: whether `warm workers` runs. It measures the PHPUnit runner's warm
  workers, so it runs where that runner, the worker, the code every runner
  shares or the measuring script changed.
- `runners`: the runners whose `runner contract` legs run, both resolutions
  of each, as a JSON list. A runner's legs run where its adapter or its
  fixture changed, or anything every runner shares.

Only a pull request is narrowed. A push to main, a scheduled run, or a run
with no base to compare against runs every gate, so a break a pull request's
narrowed run missed shows on main within one merge (ADR-0019, decision 1).

Two halves: `outputs` decides from the event and the paths, and `main` reads
them and prints.
"""
import json
import sys

RUNNERS = ("pest", "infection-13", "infection-12", "phpunit", "analysers")

# Paths that are code although they read as documentation: ARCHITECTURE.md
# holds the rule tables the Guards suite reads, .docs/reference/ the config
# reference and examples the config tests read, .docs/guide/ci/ the CI setups
# the CI tests read, and the troubleshooting guide the slugs the Docs suite
# reads. Under .github/, only ci.yml and the scripts it runs start a PHP gate.
CODE_DOCS = (
    "ARCHITECTURE.md",
    ".docs/reference/",
    ".docs/guide/ci/",
    ".docs/guide/troubleshooting.md",
    ".github/workflows/ci.yml",
    ".github/scripts/",
)

# What every runner's contract and the warm workers run through: the runner
# port and the core it drives, the processes and runtime every adapter starts,
# the gate's own mutators, the contract suites' shared code, the dependencies,
# and how CI runs them.
SHARED = (
    "src/Core/Runner/",
    "src/Core/Mutant/",
    "src/Port/Runner.php",
    "src/Port/StaticChecker.php",
    "src/Port/Processes.php",
    "src/Adapter/Process/",
    "src/Adapter/Runtime/",
    "src/Adapter/Php/",
    "src/Adapter/Opcache/",
    "src/Adapter/Project/",
    "src/Mutator/",
    "tests/Support/",
    "composer.json",
    "composer.lock",
    "phpunit.xml",
    ".github/workflows/ci.yml",
    ".github/scripts/scope.py",
)

# Infection's legs drive its PHPUnit, through the PHPUnit runner's extension.
INFECTION = ("src/Adapter/Infection/", "src/Adapter/PhpUnit/", "tests/Contract/Runner/infection-fixture/")

# Each leg's own subjects, beside SHARED.
LEGS = {
    "pest": ("src/Adapter/Pest/", "src/Attribute/", "tests/Contract/Runner/fixture/"),
    "infection-13": INFECTION,
    "infection-12": INFECTION,
    "phpunit": ("src/Adapter/PhpUnit/", "bin/mutation-gate-worker", "tests/Contract/Runner/phpunit-fixture/"),
    "analysers": (
        "src/Adapter/PhpStan/",
        "src/Adapter/Psalm/",
        "src/Adapter/Mago/",
        "src/Core/Analysis/",
        "tests/Contract/StaticChecker/",
    ),
}

# The runner contract's own files and its other fixtures, which every runner's
# leg reads.
RUNNER_CONTRACT = "tests/Contract/Runner/"
OWN_FIXTURES = (
    "tests/Contract/Runner/fixture/",
    "tests/Contract/Runner/infection-fixture/",
    "tests/Contract/Runner/phpunit-fixture/",
)

# What `warm workers` measures, beside SHARED.
WARM = (
    "src/Adapter/PhpUnit/",
    "bin/mutation-gate-worker",
    "tests/Contract/Runner/phpunit-fixture/",
    ".github/scripts/warm_measure.py",
)


def under(path: str, prefixes: tuple[str, ...]) -> bool:
    """Whether a path is one of these files, or lies under one of these directories."""
    return any(path == prefix or (prefix.endswith("/") and path.startswith(prefix)) for prefix in prefixes)


def is_code(path: str) -> bool:
    if under(path, CODE_DOCS):
        return True
    documentation = path.startswith((".docs/", ".github/")) or path.endswith(".md") or path == "LICENSE"
    return path != "" and not documentation


def reaches_runner(path: str, runner: str) -> bool:
    contract = runner != "analysers" and path.startswith(RUNNER_CONTRACT) and not under(path, OWN_FIXTURES)
    return under(path, SHARED) or under(path, LEGS[runner]) or contract




def outputs(event: str, paths: list[str] | None) -> dict[str, str]:
    """The job's outputs: every gate where the run is not a pull request or has no base, else those its paths reach."""
    if event != "pull_request" or paths is None:
        return {"code": "true", "warm": "true", "runners": json.dumps(list(RUNNERS))}
    changed = [path for path in paths if path != ""]
    code = any(is_code(path) for path in changed)
    warm = code and any(under(path, SHARED) or under(path, WARM) for path in changed)
    runners = [runner for runner in RUNNERS if code and any(reaches_runner(path, runner) for path in changed)]
    return {"code": str(code).lower(), "warm": str(warm).lower(), "runners": json.dumps(runners)}


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(__doc__, file=sys.stderr)
        return 2
    paths = None if len(sys.argv) == 3 and sys.argv[2] == "--no-base" else sys.stdin.read().splitlines()
    for name, value in outputs(sys.argv[1], paths).items():
        print(f"{name}={value}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
