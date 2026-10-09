#!/usr/bin/env python3
"""The gate run by real hook managers, as `init --hook` sets each up.

Usage: hook_managers.py <gate checkout> <work directory> <pre-commit zipapp> --findings <file>

Run by `hook managers` (ADR-0024, decisions 12 and 13). It builds one small
PHPUnit project that requires this checkout's gate through a path repository,
with CaptainHook and GrumPHP installed and GrumPHP's Composer plugin off, and
copies it into a repository of its own for each manager. In each, it runs
`init --hook=<manager>`, installs the manager's hooks as `init` says to, and
then commits and pushes through them:

- a pushed change whose new code a test kills must pass, and one whose new
  code no test covers must be refused, with the gate's failed verdict in the
  output: the gate read the refs the manager handed it;
- a commit through each manager's pre-commit hook must pass.

GrumPHP runs no pre-push hook, so it is only committed through. The
pre-commit framework's config is pointed at this checkout's commit, whose
`.pre-commit-hooks.yaml` it reads. The job's environment says it is CI, which
the gate would read; every command runs without the CI's variables, as a
developer's machine does. The findings file holds each expectation that did
not hold, with the end of what the command printed (ADR-0019, decision 5).

Two halves: `judge` and `local_repository` decide from text alone, and
`main` runs the commands.
"""
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

MANAGERS = ("captainhook", "grumphp", "pre-commit")

# The releases the job installs, exactly.
CAPTAINHOOK = "5.29.2"
GRUMPHP = "2.25.0"

# What the gate prints at the end of a judged push.
PASSED = "mutation-gate: passed"
FAILED = "mutation-gate: failed"

# What each step must do: succeed or fail, and what its output must hold.
EXPECTED = {
    "captainhook init": (True, ["Wrote captainhook.json.", "vendor/bin/captainhook install"]),
    "captainhook commit": (True, []),
    "captainhook push killed": (True, []),
    "captainhook push survived": (False, [FAILED]),
    "grumphp init": (True, ["Wrote grumphp.yml.", "GrumPHP runs no pre-push hook"]),
    "grumphp commit": (True, ["shell"]),
    "pre-commit init": (True, ["Wrote .pre-commit-config.yaml.", "pre-commit install"]),
    "pre-commit commit": (True, ["mutation-gate-pre-commit"]),
    "pre-commit push killed": (True, ["mutation-gate-pre-push"]),
    "pre-commit push survived": (False, [FAILED]),
}

# The end of a step's output a finding keeps.
TAIL = 1500

# The pre-commit framework's config as `init` writes it from a gate Composer
# installed from a path, which names no commit.
REPOSITORY = re.compile(r"^(\s*- repo: ).*$", re.MULTILINE)
REVISION = re.compile(r"^(\s*rev: ).*$", re.MULTILINE)

PHPUNIT_XML = """<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="false">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
"""

CALCULATOR = """<?php

declare(strict_types=1);

namespace Acme;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
%s}
"""

SUBTRACT = """
    public function subtract(int $a, int $b): int
    {
        return $a - $b;
    }
"""

DOUBLE = """
    public function double(int $a): int
    {
        return $a * 2;
    }
"""

TEST = """<?php

declare(strict_types=1);

namespace Acme\\Tests;

use Acme\\Calculator;
use PHPUnit\\Framework\\TestCase;

final class CalculatorTest extends TestCase
{
    public function testAdds(): void
    {
        self::assertSame(10, (new Calculator())->add(7, 3));
    }
%s}
"""

SUBTRACT_TEST = """
    public function testSubtracts(): void
    {
        self::assertSame(4, (new Calculator())->subtract(7, 3));
    }
"""


def judge(ran: dict[str, tuple[int, str]]) -> list[dict]:
    """Each expectation a step did not meet, and each step that never ran."""
    found = []
    for step, (succeeds, holds) in EXPECTED.items():
        if step not in ran:
            found.append({"path": "", "line": 0, "rule": "not-run", "detail": f"{step}: never ran"})
            continue
        code, output = ran[step]
        missing = [text for text in holds if text not in output]
        if (code == 0) != succeeds or missing:
            said = f"{step}: exited {code}, expected {'0' if succeeds else 'non-zero'}"
            if missing:
                said += f"; missing {missing}"
            found.append({"path": "", "line": 0, "rule": "unexpected", "detail": f"{said}\n{output[-TAIL:]}"})
    return found


def local_repository(config: str, checkout: str, commit: str) -> str:
    """The pre-commit framework's config, taking the gate's hooks from this checkout at this commit."""
    return REVISION.sub(lambda m: m.group(1) + commit, REPOSITORY.sub(lambda m: m.group(1) + checkout, config))


def environment() -> dict[str, str]:
    """The job's environment without what tells the gate it runs in CI."""
    return {k: v for k, v in os.environ.items() if k != "CI" and not k.startswith(("GITHUB_", "RUNNER_"))}


def run(command: list[str], where: Path) -> tuple[int, str]:
    done = subprocess.run(command, cwd=where, env=environment(), capture_output=True, text=True, check=False)
    return done.returncode, done.stdout + done.stderr


def must(command: list[str], where: Path) -> str:
    code, output = run(command, where)
    if code != 0:
        raise RuntimeError(f"{' '.join(command)} exited {code}:\n{output[-TAIL:]}")
    return output


def manifest(gate: Path, phpunit: str) -> dict:
    return {
        "name": "acme/hooks",
        "type": "project",
        "autoload": {"psr-4": {"Acme\\": "src/"}},
        "autoload-dev": {"psr-4": {"Acme\\Tests\\": "tests/"}},
        "require-dev": {
            "nightworksio/mutation-gate": "@dev",
            "phpunit/phpunit": phpunit,
            "captainhook/captainhook": CAPTAINHOOK,
            "phpro/grumphp-shim": GRUMPHP,
        },
        "repositories": [{"type": "path", "url": str(gate), "options": {"symlink": True}}],
        "minimum-stability": "dev",
        "prefer-stable": True,
        "config": {"allow-plugins": {"phpro/grumphp-shim": False}},
    }


def project(gate: Path, work: Path) -> Path:
    """The project every manager's repository starts from, installed."""
    lock = json.loads((gate / "tests/Contract/Runner/phpunit-fixture/composer.lock").read_text(encoding="utf-8"))
    phpunit = next(p["version"] for p in lock["packages"] if p["name"] == "phpunit/phpunit")
    base = work / "base"
    (base / "src").mkdir(parents=True)
    (base / "tests").mkdir()
    (base / "composer.json").write_text(json.dumps(manifest(gate, phpunit), indent=4) + "\n", encoding="utf-8")
    (base / "phpunit.xml").write_text(PHPUNIT_XML, encoding="utf-8")
    (base / "src/Calculator.php").write_text(CALCULATOR % "", encoding="utf-8")
    (base / "tests/CalculatorTest.php").write_text(TEST % "", encoding="utf-8")
    (base / ".gitignore").write_text("vendor/\n", encoding="utf-8")
    must(["composer", "update", "--no-interaction", "--no-progress"], base)
    return base


def git(where: Path, *arguments: str) -> tuple[int, str]:
    return run(["git", "-c", "user.name=hooks", "-c", "user.email=hooks@localhost", *arguments], where)


def repository(base: Path, work: Path, manager: str) -> Path:
    """A copy of the project in a repository of its own, its main branch on a remote of its own."""
    here = work / manager
    shutil.copytree(base, here, symlinks=True)
    must(["git", "init", "--quiet", "--initial-branch=main"], here)
    must(["git", "init", "--quiet", "--bare", str(work / f"{manager}.git")], work)
    must(["git", "remote", "add", "origin", str(work / f"{manager}.git")], here)
    return here


def change(here: Path, branch: str, method: str, test: str) -> None:
    must(["git", "checkout", "--quiet", "-b", branch, "main"], here)
    (here / "src/Calculator.php").write_text(CALCULATOR % method, encoding="utf-8")
    (here / "tests/CalculatorTest.php").write_text(TEST % test, encoding="utf-8")


def through(here: Path, manager: str, ran: dict[str, tuple[int, str]], pushes: bool) -> None:
    """A commit, and where the manager runs pre-push, a killed and a surviving push, through its hooks."""
    change(here, "killed", SUBTRACT, SUBTRACT_TEST)
    must(["git", "add", "--all"], here)
    ran[f"{manager} commit"] = git(here, "commit", "--quiet", "--message", "subtract")
    if not pushes:
        return
    ran[f"{manager} push killed"] = git(here, "push", "origin", "killed")
    change(here, "survived", DOUBLE, "")
    must(["git", "add", "--all"], here)
    must(["git", "-c", "user.name=hooks", "-c", "user.email=hooks@localhost", "commit", "--quiet", "--no-verify",
          "--message", "double"], here)
    ran[f"{manager} push survived"] = git(here, "push", "origin", "survived")


def set_up(here: Path, manager: str, gate: Path, zipapp: Path, ran: dict[str, tuple[int, str]]) -> None:
    """`init --hook`, the config committed and main pushed, then the manager's hooks installed."""
    ran[f"{manager} init"] = run(["vendor/bin/mutation-gate", "init", f"--hook={manager}", "--no-interaction"], here)
    if manager == "pre-commit":
        config = here / ".pre-commit-config.yaml"
        commit = must(["git", "rev-parse", "HEAD"], gate).strip()
        config.write_text(local_repository(config.read_text(encoding="utf-8"), str(gate), commit), encoding="utf-8")
    must(["git", "add", "--all"], here)
    must(["git", "-c", "user.name=hooks", "-c", "user.email=hooks@localhost", "commit", "--quiet", "--no-verify",
          "--message", "set up"], here)
    must(["git", "push", "--quiet", "--no-verify", "origin", "main"], here)
    install = {
        "captainhook": ["vendor/bin/captainhook", "install", "--force", "--no-interaction"],
        "grumphp": ["vendor/bin/grumphp", "git:init"],
        "pre-commit": [sys.executable, str(zipapp), "install"],
    }[manager]
    must(install, here)


def main(argv: list[str]) -> int:
    if len(argv) != 6 or argv[4] != "--findings":
        print(__doc__, file=sys.stderr)
        return 2
    gate, work, zipapp, findings = Path(argv[1]).resolve(), Path(argv[2]).resolve(), Path(argv[3]).resolve(), Path(argv[5])
    ran: dict[str, tuple[int, str]] = {}
    try:
        base = project(gate, work)
        for manager in MANAGERS:
            here = repository(base, work, manager)
            set_up(here, manager, gate, zipapp, ran)
            through(here, manager, ran, pushes=manager != "grumphp")
    except (RuntimeError, OSError, StopIteration, KeyError, ValueError) as broken:
        print(broken, file=sys.stderr)
    found = judge(ran)
    findings.parent.mkdir(parents=True, exist_ok=True)
    findings.write_text(json.dumps(found, indent=2) + "\n", encoding="utf-8")
    for item in found:
        print(f"::error::{item['rule']}: {item['detail'].splitlines()[0]}")
        print(item["detail"])
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
