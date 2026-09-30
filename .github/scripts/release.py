#!/usr/bin/env python3
"""What the release workflow decides: the next version, its changelog section and its tag.

Usage:
  release.py next       the version the commits since the last tag make, or
                        VERSION where given; writes version= and major= lines
                        to $GITHUB_OUTPUT, and moves gate_action.py's MAJOR at
                        a new major
  release.py prepend    puts the section on stdin at the top of CHANGELOG.md
  release.py notes      prints TAG's section of CHANGELOG.md
  release.py check-tag  refuses TAG where its major is not gate_action.py's

Each refuses with exit code 2 and says why (ADR-0019, decision 15).

Two halves: the functions below `main` read git, the environment and files,
and the ones above decide from what they read. The deciding half is what
test_release.py tests.
"""
import os
import re
import subprocess
import sys

import gate_action

VERSION = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")

# `type(scope)!: subject`, where the `!` says the change breaks.
SUBJECT = re.compile(r"^(?P<type>[a-z]+)(\([^)]*\))?(?P<breaking>!)?: ")

BREAKING_FOOTER = re.compile(r"^BREAKING[ -]CHANGE: ", re.MULTILINE)

MAJOR_LINE = re.compile(r'^MAJOR = "\d+"$', re.MULTILINE)

CHANGELOG = "CHANGELOG.md"

GATE_ACTION = os.path.join(os.path.dirname(os.path.abspath(__file__)), "gate_action.py")

LEVELS = ("patch", "minor", "major")


class Refused(Exception):
    """Why the release cannot go ahead, said to the maintainer reading the log."""


def parse(version: str) -> tuple[int, int, int]:
    matched = VERSION.match(version)
    if matched is None:
        raise Refused(f"{version!r} is not a version; it is X.Y.Z.")
    return int(matched[1]), int(matched[2]), int(matched[3])


def level(message: str) -> str | None:
    """What one commit asks of the version: a major, a minor, a patch or nothing."""
    subject = SUBJECT.match(message)
    if subject is None:
        return None
    if subject["breaking"] or BREAKING_FOOTER.search(message):
        return "major"
    return {"feat": "minor", "fix": "patch", "perf": "patch"}.get(subject["type"])


def next_version(last: str | None, messages: list[str], given: str) -> str:
    """The version to release: the one given, or the last one moved by the commits since.

    Below 1.0.0 a breaking change is a minor: 1.0.0 is given by hand.
    """
    if given:
        wanted = parse(given)
        if last is not None and wanted <= parse(last):
            raise Refused(f"{given} is not after {last}.")
        return "{}.{}.{}".format(*wanted)
    if last is None:
        raise Refused("No release is tagged yet: give the first version by hand.")
    asked = [found for found in map(level, messages) if found is not None]
    if not asked:
        raise Refused(f"Nothing to release: no feat, fix or perf since {last}.")
    major, minor, patch = parse(last)
    highest = max(asked, key=LEVELS.index)
    if highest == "major" and major > 0:
        return f"{major + 1}.0.0"
    if highest == "patch":
        return f"{major}.{minor}.{patch + 1}"
    return f"{major}.{minor + 1}.0"


def prepend(changelog: str, section: str) -> str:
    """The changelog with a version's section above every earlier one, below the header."""
    lines = changelog.splitlines(keepends=True)
    first = next((index for index, line in enumerate(lines) if line.startswith("## ")), len(lines))
    head = "".join(lines[:first]).rstrip("\n") + "\n\n"
    rest = "".join(lines[first:])
    return head + section.strip("\n") + "\n" + ("\n" + rest if rest else "")


def notes(changelog: str, version: str) -> str:
    """A version's section of the changelog, without its heading: the release's notes."""
    wanted = "{}.{}.{}".format(*parse(version))
    heading = re.compile(rf"^## \[{re.escape(wanted)}\].*$", re.MULTILINE)
    found = heading.search(changelog)
    if found is None:
        raise Refused(f"{CHANGELOG} has no section for {wanted}: merge the release pull request first.")
    following = re.compile(r"^## ", re.MULTILINE).search(changelog, found.end())
    body = changelog[found.end() : following.start() if following else len(changelog)].strip()
    if not body:
        raise Refused(f"{CHANGELOG}'s section for {wanted} is empty.")
    return body + "\n"


def major_refusal(tag: str, major: str) -> str | None:
    """Why a tag is not a release of the action's major, or nothing where it is."""
    found = str(parse(tag)[0])
    if found != major:
        return (
            f"{tag} is major {found}, and gate_action.py's MAJOR is {major}. "
            "Release the major change through the release pull request, which moves MAJOR."
        )
    return None


def with_major(source: str, major: str) -> str:
    """gate_action.py with its MAJOR set."""
    moved, count = MAJOR_LINE.subn(f'MAJOR = "{major}"', source)
    if count != 1:
        raise Refused("gate_action.py has no single MAJOR line to move.")
    return moved


def main(argv: list[str]) -> int:
    commands = {"next": _next, "prepend": _prepend, "notes": _notes, "check-tag": _check_tag}
    if len(argv) != 2 or argv[1] not in commands:
        print(__doc__, file=sys.stderr)
        return 2
    try:
        commands[argv[1]]()
    except Refused as refused:
        print(f"::error::{refused}", file=sys.stderr)
        return 2
    return 0


def _next() -> None:
    last = _last_tag()
    messages = _messages(last)
    version = next_version(last, messages, os.environ.get("VERSION", ""))
    major = str(parse(version)[0])
    if major != gate_action.MAJOR:
        _write_file(GATE_ACTION, with_major(_read_file(GATE_ACTION), major))
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as file:
        file.write(gate_action.output_lines({"version": version, "major": major}))


def _prepend() -> None:
    _write_file(CHANGELOG, prepend(_read_file(CHANGELOG), sys.stdin.read()))


def _notes() -> None:
    sys.stdout.write(notes(_read_file(CHANGELOG), os.environ["TAG"]))


def _check_tag() -> None:
    why = major_refusal(os.environ["TAG"], gate_action.MAJOR)
    if why is not None:
        raise Refused(why)


def _last_tag() -> str | None:
    found = subprocess.run(
        ["git", "describe", "--tags", "--abbrev=0", "--match", "v[0-9]*.[0-9]*.[0-9]*"],
        capture_output=True,
        text=True,
        check=False,
    )
    return found.stdout.strip() if found.returncode == 0 else None


def _messages(last: str | None) -> list[str]:
    since = [f"{last}..HEAD"] if last else ["HEAD"]
    log = subprocess.run(
        ["git", "log", "--first-parent", "--format=%B%x00", *since],
        capture_output=True,
        text=True,
        check=True,
    )
    return [message.strip() for message in log.stdout.split("\0") if message.strip()]


def _read_file(path: str) -> str:
    with open(path, encoding="utf-8") as file:
        return file.read()


def _write_file(path: str, text: str) -> None:
    with open(path, "w", encoding="utf-8") as file:
        file.write(text)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
