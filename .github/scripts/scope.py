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
- `site`: whether `site` builds the docs site and checks the links the gate
  prints against it: where the docs, the site, its workflows or what prints
  a link changed (ADR-0038).

Only a pull request is narrowed. A push to main, a scheduled run, or a run
with no base to compare against runs every gate, so a break a pull request's
narrowed run missed shows on main within one merge (ADR-0019).

Two halves: `outputs` decides from the event and the paths, and `main` reads
them and prints.
"""
import json
import sys

RUNNERS = ("pest", "infection-13", "infection-12", "phpunit", "phpspec", "codeception", "analysers")

# Paths that are code although they read as documentation: ARCHITECTURE.md
# holds the rule tables the Guards suite reads, .docs/reference/ the config
# reference and examples the config tests read, .docs/guide/ci/ the CI setups
# the CI tests read, and the troubleshooting guide the slugs the Docs suite
# reads. Under .github/, only ci.yml, the end-to-end workflow it calls and the
# scripts they run start a PHP gate.
CODE_DOCS = (
    "ARCHITECTURE.md",
    ".docs/reference/",
    ".docs/guide/ci/",
    ".docs/guide/troubleshooting.md",
    ".github/workflows/ci.yml",
    ".github/workflows/end-to-end.yml",
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

INFECTION = ("src/Adapter/Infection/", "tests/Contract/Runner/infection-fixture/")

# What the docs site is built from, how it is built, and what prints a link
# into it (ADR-0038).
SITE = (
    ".docs/",
    ".site/",
    "README.md",
    "src/Core/ThisPackage.php",
    "src/Core/Troubleshooting/",
    "src/Core/Report/Term.php",
    "tests/Docs/PrintedLinksTest.php",
    ".github/workflows/site.yml",
    ".github/workflows/pages.yml",
    ".github/workflows/ci.yml",
    ".github/scripts/scope.py",
)

# The native runner, which drives each framework that has no runner of its own.
NATIVE = ("src/Adapter/Native/", "src/Core/Native/")

# Where a run reads the tests whose issues failed it, which Pest's plugin
# reads through the PHPUnit adapter's reader.
RUN_ISSUES = ("src/Core/Test/RunIssues.php",)

# The PHPUnit framework the native runner drives, and its warm worker.
NATIVE_PHPUNIT = (
    *NATIVE,
    *RUN_ISSUES,
    "src/Adapter/PhpUnit/",
    "bin/mutation-gate-worker",
    "tests/Contract/Runner/phpunit-fixture/",
)

# The PhpSpec framework the native runner drives, its launcher, and the
# attribute its examples read.
NATIVE_PHPSPEC = (
    *NATIVE,
    "src/Adapter/PhpSpec/",
    "src/Attribute/",
    "bin/mutation-gate-phpspec",
    "tests/Contract/Runner/phpspec-fixture/",
)

# The Codeception framework the native runner drives, and the attribute its
# tests read.
NATIVE_CODECEPTION = (
    *NATIVE,
    "src/Adapter/Codeception/",
    "src/Attribute/",
    "tests/Contract/Runner/codeception-fixture/",
)

# Each leg's own subjects, beside SHARED.
LEGS = {
    "pest": (
        *RUN_ISSUES,
        "src/Adapter/Pest/",
        "bin/mutation-gate-pest-worker",
        "bin/mutation-gate-pest-client",
        "src/Adapter/PhpUnit/IssueKillers.php",
        "src/Adapter/PhpUnit/IssueKind.php",
        "src/Attribute/",
        "src/Cli/PestPlugin.php",
        "tests/Contract/Runner/fixture/",
    ),
    "infection-13": INFECTION,
    "infection-12": INFECTION,
    "phpunit": NATIVE_PHPUNIT,
    "phpspec": NATIVE_PHPSPEC,
    "codeception": NATIVE_CODECEPTION,
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
    "tests/Contract/Runner/phpspec-fixture/",
    "tests/Contract/Runner/codeception-fixture/",
)

# What `warm workers` measures, beside SHARED.
WARM = (
    *NATIVE_PHPUNIT,
    ".github/scripts/warm_measure.py",
    ".github/workflows/end-to-end.yml",
)


def under(path: str, prefixes: tuple[str, ...]) -> bool:
    """Whether a path is one of these files, or lies under one of these directories."""
    return any(path == prefix or (prefix.endswith("/") and path.startswith(prefix)) for prefix in prefixes)


def is_code(path: str) -> bool:
    if under(path, CODE_DOCS):
        return True
    documentation = path.startswith((".docs/", ".site/", ".github/")) or path.endswith(".md") or path == "LICENSE"
    return path != "" and not documentation


def reaches_runner(path: str, runner: str) -> bool:
    contract = runner != "analysers" and path.startswith(RUNNER_CONTRACT) and not under(path, OWN_FIXTURES)
    return under(path, SHARED) or under(path, LEGS[runner]) or contract




def outputs(event: str, paths: list[str] | None) -> dict[str, str]:
    """The job's outputs: every gate where the run is not a pull request or has no base, else those its paths reach."""
    if event != "pull_request" or paths is None:
        return {"code": "true", "warm": "true", "runners": json.dumps(list(RUNNERS)), "site": "true"}
    changed = [path for path in paths if path != ""]
    code = any(is_code(path) for path in changed)
    warm = code and any(under(path, SHARED) or under(path, WARM) for path in changed)
    runners = [runner for runner in RUNNERS if code and any(reaches_runner(path, runner) for path in changed)]
    site = any(under(path, SITE) for path in changed)
    return {"code": str(code).lower(), "warm": str(warm).lower(), "runners": json.dumps(runners), "site": str(site).lower()}


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
