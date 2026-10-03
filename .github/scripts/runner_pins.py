#!/usr/bin/env python3
"""A runner release the canary saw pass that composer.json does not take yet.

Usage: runner_pins.py <installed.json> [<installed.json> ...] [--findings <file>]

Run by the runner canary once the Runner contract suite has passed against
the newest releases (ADR-0017, decision 8). Each pinned runner package is held
by one line of composer.json: pest-plugin-mutate by its `conflict`, Infection
by its `require-dev`. For each one a fixture installed, it fails where that
line refuses the release that passed, naming the line to write, so each
release is taken by a pull request within a day. With --findings, what it
found is also written there as the job's evidence (ADR-0019, decision 5).

Two halves: `satisfies`, `refused` and `line_to_write` decide from text, and
`main` reads the files.
"""
import json
import re
import sys
from pathlib import Path

# Each pinned runner package, by the composer.json key whose line holds it,
# and the fixture manifests that copy that line.
PINS = {
    "pestphp/pest-plugin-mutate": ("conflict", ["tests/Contract/Runner/fixture/composer.json"]),
    "infection/infection": (
        "require-dev",
        [
            "tests/Contract/Runner/infection-fixture/composer.json",
            "tests/Contract/Runner/infection-fixture/phpunit-12/composer.json",
        ],
    ),
}

# One comparison of a constraint: an operator, then a version.
TERM = re.compile(r"\A(<=|>=|<|>|!=|==|=|~|\^)?v?(\d+(?:\.\d+)*)(\.\*)?\Z")

# The upper bound a conflict that pins a release ends with: "<5.0.2 || >5.0.2".
UPPER = re.compile(r"\A(?P<rest>.*\|\|\s*)>v?\d+(?:\.\d+)*\Z")


def version(text: str) -> tuple[int, ...]:
    """A release as numbers, padded to three, so 5.1 compares as 5.1.0."""
    numbers = [int(part) for part in text.lstrip("v").split(".")]
    return tuple(numbers + [0] * (3 - len(numbers)))


def next_of(numbers: tuple[int, ...], place: int) -> tuple[int, ...]:
    """The first release past every one that shares these numbers up to this place."""
    raised = list(numbers[: place + 1])
    raised[place] += 1
    return tuple(raised + [0] * (len(numbers) - len(raised)))


def caret_place(numbers: tuple[int, ...]) -> int:
    """Where a caret lets a release rise to: its first number that is not zero."""
    return next((place for place, number in enumerate(numbers[:-1]) if number != 0), len(numbers) - 1)


def matches(release: tuple[int, ...], term: str) -> bool:
    """Whether a release meets one comparison, as Composer reads it."""
    parsed = TERM.match(term)
    if parsed is None:
        raise ValueError(f"a constraint term the canary cannot read: {term}")
    operator, written, wildcard = parsed.group(1) or "=", parsed.group(2), parsed.group(3)
    bound = version(written)
    given = len(written.split("."))
    comparisons = {
        "<": release < bound,
        "<=": release <= bound,
        ">": release > bound,
        ">=": release >= bound,
        "!=": release != bound,
        "~": bound <= release < next_of(bound, max(given - 2, 0)),
        "^": bound <= release < next_of(bound, caret_place(bound)),
    }
    if wildcard:
        return bound <= release < next_of(bound, given - 1)
    return comparisons.get(operator, release == bound)


def satisfies(release: str, constraint: str) -> bool:
    """Whether a release meets a constraint: any of its `||` alternatives, each every one of its terms."""
    numbers = version(release)
    alternatives = [alternative.replace(",", " ").split() for alternative in constraint.split("||")]
    return any(all(matches(numbers, term) for term in terms) for terms in alternatives if terms)


def refused(key: str, release: str, constraint: str) -> bool:
    """Whether a line of composer.json refuses a release: a conflict by meeting it, a requirement by not."""
    return satisfies(release, constraint) if key == "conflict" else not satisfies(release, constraint)


def line_to_write(key: str, release: str, constraint: str) -> str:
    """The constraint that takes the release too: a pinning conflict's upper bound raised to it, or a requirement widened by its minor."""
    if key == "conflict":
        bound = UPPER.match(constraint)
        return f"{bound.group('rest')}>{release}" if bound else ""
    major, minor = version(release)[:2]
    return f"{constraint} || ~{major}.{minor}.0"


def behind(installed: dict[str, str], manifest: dict) -> list[dict]:
    """Each pinned package whose installed release composer.json refuses, as evidence."""
    found = []
    for package, (key, fixtures) in PINS.items():
        constraint = manifest.get(key, {}).get(package)
        release = installed.get(package)
        if constraint is None or release is None or not refused(key, release, constraint):
            continue
        written = line_to_write(key, release, constraint)
        where = " and ".join(["composer.json", *fixtures])
        detail = (
            f'{package} {release} passes the Runner contracts, and the {key} "{constraint}" refuses it. '
            + (f'Write "{package}": "{written}" in the {key} of {where}.' if written else f"Change the {key} of {where} so it takes {release}.")
        )
        found.append({"path": "composer.json", "line": 0, "rule": "refuses-a-passing-release", "detail": detail})
    return found


def releases(text: str) -> dict[str, str]:
    """Each package Composer's installed.json lists, by its name, at its release."""
    listed = json.loads(text)
    packages = listed.get("packages", []) if isinstance(listed, dict) else listed
    return {str(package["name"]): str(package["version"]) for package in packages}


def main(argv: list[str]) -> int:
    arguments = argv[1:]
    findings_file = None
    if "--findings" in arguments:
        at = arguments.index("--findings")
        findings_file = arguments[at + 1]
        arguments = arguments[:at] + arguments[at + 2 :]
    installed = {}
    for file in arguments:
        installed.update(releases(Path(file).read_text(encoding="utf-8")))
    found = behind(installed, json.loads(Path("composer.json").read_text(encoding="utf-8")))
    if findings_file:
        Path(findings_file).parent.mkdir(parents=True, exist_ok=True)
        Path(findings_file).write_text(json.dumps(found), encoding="utf-8")
    for item in found:
        print(f"::error::{item['detail']}")
    if not found:
        print("runner pins: composer.json takes every pinned release that passed")
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
