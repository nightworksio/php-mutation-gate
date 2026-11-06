#!/usr/bin/env python3
"""Which of ci.yml's gated jobs a run is worth, from the paths it changed.

Usage: BASE=<commit> scope.py <event> < changed paths, one per line
       scope.py <event> --no-base

Run by `what changed`. It prints the job's outputs, one `name=value` per line:

- `jobs`: the ids of the jobs of ci.yml this script gates that run, as a JSON
  list. A job runs where one of its inputs changed (INPUTS), a script it runs,
  or its own text in ci.yml. `runner-contracts` also runs where a leg runs,
  and `end-to-end` where one of its jobs does.
- `runners`: the runners whose `runner contract` legs run, both resolutions
  of each, as a JSON list. A runner's legs run where its adapter or its
  fixture changed, the test support its own tests use (legs.json), or what
  every runner runs through.
- `warm`: whether end-to-end's `warm workers` runs. It measures the PHPUnit
  runner's warm workers in another project, so it runs where that runner,
  its worker, what every runner runs through or the measuring script changed.
- `hooks`: whether end-to-end's `hook managers` runs. It installs this
  checkout into another project and drives it through the hook managers, so
  it runs where the program outside the suites changed.

What each job reads (INPUTS):

- `checks`, `lowest` and `phar`: the program, every path that is neither
  documentation nor another gate's own file (`is_program`). `guards` reads
  ARCHITECTURE.md beside it, whose rule tables the Guards suite plants.
- `tests` and `alone`: the program and what the suites read beside it, the
  documents CODE_DOCS lists, the action and the reusable workflow. `tests`
  also reads SonarCloud's settings, since `sonarcloud` analyses its coverage.
- `mutation`: the program, the action and the reusable workflow, which the
  self-gate runs. The gate's own plan then decides what the change reaches
  (ADR-0005), so a test-only change is judged as the gate judges one.
- `site`: the docs, the site and what prints a link into it (ADR-0038).
- `replay`: what decides what a change reaches and how a plan carries and
  cuts (REPLAY), which it plans the newest pull requests again with. A push
  to main runs none: ci.yml's own condition leaves it to the night.

A script under .github/scripts reaches each job that runs it, through the
jobs of a workflow that job calls too, and any script those import. Every
other script, and its test, is the `scripts` job's alone.

A change to ci.yml or to end-to-end.yml, the workflow it calls, is read job
by job against the base commit. A change to what every job of ci.yml shares
(its `on`, `env`, `permissions`, `concurrency` or `defaults`), or to the
scope job, runs every job, and one to what every job of end-to-end.yml shares
runs both of its jobs. A job added or changed runs itself, so editing a job
that runs on every pull request, such as the `needs` of the job that gathers
the evidence, runs no gated job, and neither does removing a job. A change
outside the jobs' keys, such as a comment, runs nothing. Without the base to
read, a change to either workflow runs every job.

Only a pull request is narrowed. A push to main, a scheduled run, or a run
with no base to compare against runs every job, so a break a pull request's
narrowed run missed shows on main within one merge (ADR-0019).

Two halves: `outputs` decides from the event and the paths, and `main` reads
them and prints.
"""
import json
import os
import re
import subprocess
import sys
from collections.abc import Callable
from functools import cache
from pathlib import Path

# The repository's root, from this script's place in it.
ROOT = Path(__file__).resolve().parents[2]

# Each runner's leg of `runner contract`, with the test support its tests use
# (leg_support.py), and the runners in the order their legs run.
LEG_TABLE = json.loads(Path(__file__).resolve().with_name("legs.json").read_text(encoding="utf-8"))
RUNNERS = tuple(LEG_TABLE)

# The jobs of ci.yml this script gates, in the workflow's order.
GATED = ("site", "alone", "guards", "checks", "tests", "lowest", "phar", "image", "runner-contracts", "end-to-end", "mutation", "replay")

# The job that runs the legs, the end-to-end workflow's jobs by the input that
# runs each, and the job that decides every output.
LEGS_JOB = "runner-contract"
END_TO_END_CALLER = "end-to-end"
END_TO_END_JOBS = ("warm", "hooks")
SCOPE_JOB = "scope"

# Paths that the suites read although they read as documentation: ARCHITECTURE.md
# holds the rule tables the Guards suite reads, .docs/reference/ the config
# reference and examples the config tests read, .docs/guide/ci/ the CI setups
# the CI tests read, and the troubleshooting guide the slugs the Docs suite
# reads.
CODE_DOCS = (
    "ARCHITECTURE.md",
    ".docs/reference/",
    ".docs/guide/ci/",
    ".docs/guide/troubleshooting.md",
)

# The action, and the reusable workflow the self-gate calls, which the suites
# read and the mutation job runs.
SHIPPED_CI = ("action.yml", ".github/workflows/mutation-gate.yml")

# The files of the gates that read no PHP: the spelling, link and markdown
# checks, the changelog, the image, and the benchmark, which bench.yml runs.
OTHER_GATES = (
    "typos.toml",
    "lychee.toml",
    ".markdownlint.jsonc",
    "cliff.toml",
    "Dockerfile",
    ".dockerignore",
    "bench/",
)

# The suites, which no project the gate is installed into loads.
TESTS = "tests/"

# SonarCloud's settings, which `sonarcloud` reads with the coverage `tests` writes.
SONAR = ("sonar-project.properties",)

# Where the scripts are, the workflows, the workflow whose jobs this decides,
# and the end-to-end workflow it calls.
SCRIPTS = ".github/scripts/"
WORKFLOWS = ".github/workflows/"
CI = "ci.yml"
END_TO_END = "end-to-end.yml"
READ_BY_JOB = (f"{WORKFLOWS}{CI}", f"{WORKFLOWS}{END_TO_END}")

# A script a job runs, a workflow of this repository a job calls, a script's
# import of another, and the input an end-to-end job's `if:` reads.
RUN = re.compile(r"\.github/scripts/([a-z0-9_]+\.py)")
CALLED = re.compile(r"^    uses: \./\.github/workflows/([a-z0-9-]+\.ya?ml)", re.M)
IMPORTED = re.compile(r"^(?:from|import) ([a-z0-9_]+)", re.M)
INPUT_READ = re.compile(r"^    if: inputs\.([a-z]+)\b", re.M)

# What every runner's contract and the warm workers run through: the runner
# port and the core it drives, the processes and runtime every adapter starts,
# the gate's own mutators, and the dependencies.
RUNTIME = (
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
    "composer.json",
    "composer.lock",
)

# How every leg runs the Contract suite, beside RUNTIME and the test support
# its own tests use.
CONTRACT_RUN = ("phpunit.xml", ".github/scripts/legs.json")

INFECTION = ("src/Adapter/Infection/", "src/Core/Coverage/PhpUnitXml.php", "tests/Contract/Runner/infection-fixture/")

# What the docs site is built from, how it is built, and what prints a link
# into it (ADR-0038).
SITE = (
    ".docs/",
    ".site/",
    ".github/scripts/decisions_index.py",
    "README.md",
    "src/Core/ThisPackage.php",
    "src/Core/Troubleshooting/",
    "src/Core/Report/Term.php",
    "tests/Docs/PrintedLinksTest.php",
    ".github/workflows/site.yml",
    ".github/workflows/pages.yml",
)

# What decides what a change reaches and how a plan carries and cuts, which
# `replay` plans the newest pull requests again with.
REPLAY = (
    "src/Core/Reach/",
    "src/Core/Plan/",
    "src/Core/Change/",
    "src/Core/Proof/",
    "src/Cli/Flow/",
    "src/Cli/Command/PlanCommand.php",
    "src/Adapter/Git/",
    "src/Core/Config/Definition/ReachKeys.php",
    "mutation-gate.json",
)

# What the image is built from beside the PHAR, and how the PHAR is built
# (ADR-0022).
IMAGE = ("Dockerfile", ".dockerignore", "box.json.dist", "scoper.inc.php", "scripts/phar.sh", ".github/workflows/image.yml")

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

# The Testo framework the native runner drives, its launcher, the
# XML coverage it measures with, and the attribute its tests read.
NATIVE_TESTO = (
    *NATIVE,
    "src/Adapter/Testo/",
    "src/Attribute/",
    "src/Core/Coverage/PhpUnitXml.php",
    "bin/mutation-gate-testo",
    "tests/Contract/Runner/testo-fixture/",
)

# Each leg's own subjects, beside RUNTIME.
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
    "testo": NATIVE_TESTO,
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
    "tests/Contract/Runner/testo-fixture/",
)

# What `warm workers` measures, beside RUNTIME.
WARM = (
    *NATIVE_PHPUNIT,
    ".github/scripts/warm_measure.py",
)


def under(path: str, prefixes: tuple[str, ...]) -> bool:
    """Whether a path is one of these files, or lies under one of these directories."""
    return any(path == prefix or (prefix.endswith("/") and path.startswith(prefix)) for prefix in prefixes)


def is_documentation(path: str) -> bool:
    """Whether a path is documentation, or under .github/, where a job's script or workflow is read by what runs it."""
    return path.startswith((".docs/", ".site/", ".github/")) or path.endswith(".md") or path == "LICENSE"


def is_program(path: str) -> bool:
    """Whether a path is the program the PHP gates build, analyse and run: neither documentation, nor another gate's file, nor the shipped CI."""
    return path != "" and not is_documentation(path) and not under(path, OTHER_GATES + SONAR + SHIPPED_CI)


def suites_read(path: str) -> bool:
    """Whether the test suites read a path: the program, the documents they check, the action and the reusable workflow."""
    return is_program(path) or under(path, CODE_DOCS + SHIPPED_CI)


# What each gated job reads, by its id; `runner-contracts` and `end-to-end`
# follow the jobs they gather.
INPUTS: dict[str, Callable[[str], bool]] = {
    "site": lambda path: under(path, SITE),
    "alone": suites_read,
    "guards": lambda path: is_program(path) or path == "ARCHITECTURE.md",
    "checks": is_program,
    "tests": lambda path: suites_read(path) or under(path, SONAR),
    "lowest": is_program,
    "phar": is_program,
    "image": lambda path: path in IMAGE,
    "mutation": lambda path: is_program(path) or under(path, SHIPPED_CI),
    "replay": lambda path: under(path, REPLAY),
}


def jobs(workflow: str) -> list[str]:
    """Each job of a workflow, as the lines under its id."""
    lines = workflow.split("\n")
    starts = [at for at, line in enumerate(lines) if re.match(r"^  [a-z0-9_-]+:\s*$", line)]
    return ["\n".join(lines[start:end]) for start, end in zip(starts, [*starts[1:], len(lines)])]


def job_id(job: str) -> str:
    return job.split("\n", 1)[0].strip().rstrip(":")


def job_scripts(workflows: dict[str, str], scripts: dict[str, str]) -> dict[str, frozenset[str]]:
    """The scripts each job of ci.yml runs, by its id, and each job of end-to-end.yml, by the input that runs it.

    A job runs the scripts its text names, those of every job of a workflow it
    calls, and every script those import, transitively.
    """
    def named(texts: list[str]) -> frozenset[str]:
        found = {name for text in texts for name in RUN.findall(text) if name in scripts}
        pending = list(found)
        while pending:
            for module in IMPORTED.findall(scripts[pending.pop()]):
                name = f"{module}.py"
                if name in scripts and name not in found:
                    found.add(name)
                    pending.append(name)
        return frozenset(found)

    def called(job: str) -> list[str]:
        texts, pending, seen = [job], CALLED.findall(job), set()
        while pending:
            name = pending.pop()
            if name in seen:
                continue
            seen.add(name)
            for inner in jobs(workflows.get(name, "")):
                texts.append(inner)
                pending.extend(CALLED.findall(inner))
        return texts

    by_job = {job_id(job): named(called(job)) for job in jobs(workflows.get(CI, ""))}
    for job in jobs(workflows.get(END_TO_END, "")):
        for read in INPUT_READ.findall(job):
            by_job[read] = by_job.get(read, frozenset()) | named(called(job))
    return by_job


@cache
def own_job_scripts() -> dict[str, frozenset[str]]:
    """The scripts each of this repository's jobs runs, as paths from the root."""
    workflows = {path.name: path.read_text(encoding="utf-8") for path in (ROOT / WORKFLOWS).glob("*.y*ml")}
    scripts = {path.name: path.read_text(encoding="utf-8") for path in (ROOT / SCRIPTS).glob("*.py")}
    return {job: frozenset(f"{SCRIPTS}{name}" for name in names) for job, names in job_scripts(workflows, scripts).items()}


def meaningful(text: str) -> list[str]:
    """A workflow's lines as YAML reads them: no comment line and no blank one, each without its trailing space."""
    lines = [line.rstrip() for line in text.split("\n")]
    return [line for line in lines if line.strip() != "" and not line.lstrip().startswith("#")]


def shape(workflow: str) -> tuple[list[str], dict[str, list[str]]]:
    """A workflow's keys outside `jobs`, and each job by its id, as their meaningful lines."""
    lines = workflow.split("\n")
    start = next((at for at, line in enumerate(lines) if line.rstrip() == "jobs:"), len(lines))
    by_id = {job_id(job): meaningful(job) for job in jobs("\n".join(lines[start + 1 :]))}
    return meaningful("\n".join(lines[:start])), by_id


def workflow_jobs(name: str, before: str, after: str) -> set[str] | None:
    """The jobs a change to one workflow read job by job runs: None for every job, else the ids of ci.yml's it added or changed, or the inputs of end-to-end.yml's."""
    shared_before, jobs_before = shape(before)
    shared_after, jobs_after = shape(after)
    changed = {job: lines for job, lines in jobs_after.items() if jobs_before.get(job) != lines}
    if name == END_TO_END:
        if shared_before != shared_after:
            return set(END_TO_END_JOBS)
        return {read for lines in changed.values() for read in INPUT_READ.findall("\n".join(lines))}
    if shared_before != shared_after or SCOPE_JOB in changed:
        return None
    return set(changed)


def reaches_runner(path: str, runner: str) -> bool:
    contract = runner != "analysers" and path.startswith(RUNNER_CONTRACT) and not under(path, OWN_FIXTURES)
    own = under(path, LEGS[runner]) or path in LEG_TABLE[runner]["support"]
    return under(path, RUNTIME + CONTRACT_RUN) or own or contract


def everything() -> dict[str, str]:
    return {"jobs": json.dumps(list(GATED)), "runners": json.dumps(list(RUNNERS)), "warm": "true", "hooks": "true"}


def outputs(
    event: str,
    paths: list[str] | None,
    workflows: dict[str, tuple[str, str]] | None = None,
) -> dict[str, str]:
    """The job's outputs: every job where the run is not a pull request or has no base, else those its paths reach.

    `workflows` holds each changed workflow read job by job, by its path, as
    its text at the base and at the head; one changed but not held runs every
    job.
    """
    if event != "pull_request" or paths is None:
        return everything()
    changed = [path for path in paths if path != ""]
    named: set[str] = set()
    for path in changed:
        if path in READ_BY_JOB:
            texts = (workflows or {}).get(path)
            found = None if texts is None else workflow_jobs(Path(path).name, *texts)
            if found is None:
                return everything()
            named |= found
    scripts = own_job_scripts()

    def runs(job: str, reads: Callable[[str], bool]) -> bool:
        return job in named or any(reads(path) or path in scripts.get(job, ()) for path in changed)

    every_leg = runs(LEGS_JOB, lambda path: False)
    runners = [runner for runner in RUNNERS if every_leg or any(reaches_runner(path, runner) for path in changed)]
    called = END_TO_END_CALLER in named
    warm = called or runs("warm", lambda path: under(path, RUNTIME + WARM))
    hooks = called or runs("hooks", lambda path: is_program(path) and not path.startswith(TESTS))
    gathered = {"runner-contracts": runners != [] or "runner-contracts" in named, END_TO_END_CALLER: warm or hooks}
    run = [job for job in GATED if gathered.get(job, False) or runs(job, INPUTS.get(job, lambda path: False))]
    return {"jobs": json.dumps(run), "runners": json.dumps(runners), "warm": str(warm).lower(), "hooks": str(hooks).lower()}


def read_workflows(base: str, paths: list[str]) -> dict[str, tuple[str, str]]:
    """Each changed workflow read job by job, as git holds it at the base and as the head checkout holds it."""
    held = {}
    for path in paths:
        if path not in READ_BY_JOB:
            continue
        shown = subprocess.run(["git", "show", f"{base}:{path}"], capture_output=True, text=True, check=False, cwd=ROOT)
        head = ROOT / path
        held[path] = (shown.stdout if shown.returncode == 0 else "", head.read_text(encoding="utf-8") if head.is_file() else "")
    return held


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(__doc__, file=sys.stderr)
        return 2
    paths = None if len(sys.argv) == 3 and sys.argv[2] == "--no-base" else sys.stdin.read().splitlines()
    base = os.environ.get("BASE", "")
    workflows = read_workflows(base, paths) if base != "" and paths is not None else None
    for name, value in outputs(sys.argv[1], paths, workflows).items():
        print(f"{name}={value}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
