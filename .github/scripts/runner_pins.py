#!/usr/bin/env python3
"""A runner release the canary saw pass that the package does not take, or does not count as tested, yet.

Usage: runner_pins.py <installed.json> [<installed.json> ...] [--findings <file>]

Run by the runner canary once the Runner contract suite has passed against
the newest releases (ADR-0017, ADR-0041). Each pinned runner package is held
by one line of composer.json: pest-plugin-mutate by its `conflict`, Infection
by its `require-dev`. For each one a fixture installed, it fails where that
line refuses the release that passed, naming the line to write. It also fails
where the release that passed is newer than the newest pest-plugin-mutate
release doctor counts as tested, naming the constant to raise. So each release
is taken by a pull request within a day. With --findings, what it found is
also written there as the job's evidence (ADR-0019).

Two halves: `satisfies`, `refused`, `line_to_write`, `behind` and `untested`
decide from text, and `main` reads the files.
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

# The major a conflict refuses from, which it ends with: "<5.0.2 || >=6.0".
UPPER = re.compile(r"\A(?P<rest>.*\|\|\s*)>=v?\d+(?:\.\d+)*\Z")

# Each runner package whose newest tested release doctor reads, by the file
# that holds it as the constant NEWEST.
TESTED = {"pestphp/pest-plugin-mutate": "src/Core/Doctor/Check/PestMutateUntested.php"}

# The newest tested release, as the file declares it.
NEWEST = re.compile(r"const string NEWEST = '(?P<release>[^']+)';")


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
    """The constraint that takes the release too: a conflict's major raised past it, or a requirement widened by its minor."""
    if key == "conflict":
        bound = UPPER.match(constraint)
        return f"{bound.group('rest')}>={version(release)[0] + 1}.0" if bound else ""
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


def untested(installed: dict[str, str], newest: dict[str, str]) -> list[dict]:
    """Each package whose installed release is newer than the newest one doctor counts as tested, as evidence."""
    found = []
    for package, file in TESTED.items():
        release = installed.get(package)
        known = newest.get(package)
        if release is None or known is None or version(release) <= version(known):
            continue
        detail = (
            f"{package} {release} passes the Runner contracts, and doctor counts {known} as the newest release tested. "
            f"Write NEWEST = '{release.lstrip('v')}' in {file}."
        )
        found.append({"path": file, "line": 0, "rule": "untested-passing-release", "detail": detail})
    return found


def tested(read) -> dict[str, str]:
    """The newest tested release of each package in TESTED, read through `read` from the file that declares it."""
    found = {}
    for package, file in TESTED.items():
        declared = NEWEST.search(read(file))
        if declared is None:
            raise ValueError(f"{file} declares no NEWEST")
        found[package] = declared.group("release")
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
    found = behind(installed, json.loads(Path("composer.json").read_text(encoding="utf-8"))) + untested(
        installed, tested(lambda file: Path(file).read_text(encoding="utf-8"))
    )
    if findings_file:
        Path(findings_file).parent.mkdir(parents=True, exist_ok=True)
        Path(findings_file).write_text(json.dumps(found), encoding="utf-8")
    for item in found:
        print(f"::error::{item['detail']}")
    if not found:
        print("runner pins: the package takes, and counts as tested, every pinned release that passed")
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
