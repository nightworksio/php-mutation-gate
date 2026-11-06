#!/usr/bin/env python3
"""The copies of the docs site, one for each minor and one for main (ADR-0038).

Usage:
  site_copies.py plan       prints, as JSON, every copy the site holds: its
                            path below the site's base, the ref its docs are
                            built from, and whether its pages are ordered
                            strictly; then the versions the picker lists
  site_copies.py versions   prints the versions the picker reads, as JSON,
                            which the site serves at its root as versions.json

The refs are the tags git lists as vX.Y.Z or vX.Y.Z-<pre-release>. Each minor
has a copy at /X.Y, built from its newest tag. `main` has one at /next. The
root holds the newest minor that has a release, not only a pre-release, and
`main`'s docs while there is none. Only `main`'s pages, at /next and at a root
built from `main`, are held to the sidebar's order strictly, since a tag's
docs keep the pages they were tagged with.

Two halves: `copies` and `versions` decide, and `main` reads git.
"""
import json
import re
import subprocess
import sys

TAG = re.compile(r"^v(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)(?:-(?P<pre>[0-9A-Za-z.-]+))?$")

MAIN = "main"

NEXT = "/next"


def parsed(tag: str) -> tuple | None:
    """A tag's version, sortable: a release after each of its pre-releases; none where it is no version."""
    matched = TAG.match(tag)
    if not matched:
        return None
    pre = matched.group("pre")
    numbers = (int(matched.group("major")), int(matched.group("minor")), int(matched.group("patch")))
    if pre is None:
        return numbers + ((1, ()),)
    identifiers = tuple((0, int(part), "") if part.isdigit() else (1, 0, part) for part in pre.split("."))
    return numbers + ((0, identifiers),)


def newest_by_minor(tags: list[str]) -> dict[str, dict]:
    """Each minor, `X.Y`, with its newest tag and whether any of its tags is a release."""
    minors: dict[str, dict] = {}
    for tag in tags:
        version = parsed(tag)
        if version is None:
            continue
        minor = f"{version[0]}.{version[1]}"
        released = version[3][0] == 1
        known = minors.get(minor)
        if known is None or version > known["version"]:
            minors[minor] = {"tag": tag, "version": version, "released": released or (known or {}).get("released", False)}
        elif released:
            known["released"] = True
    return minors


def ordered_minors(minors: dict[str, dict]) -> list[str]:
    return sorted(minors, key=lambda minor: tuple(int(part) for part in minor.split(".")), reverse=True)


def copies(tags: list[str]) -> list[dict]:
    """Every copy of the site: the root, `/next`, and one for each minor, newest first."""
    minors = newest_by_minor(tags)
    released = [minor for minor in ordered_minors(minors) if minors[minor]["released"]]
    root = minors[released[0]]["tag"] if released else MAIN
    found = [{"path": "", "ref": root, "strict": root == MAIN}, {"path": NEXT, "ref": MAIN, "strict": True}]
    found += [{"path": f"/{minor}", "ref": minors[minor]["tag"], "strict": False} for minor in ordered_minors(minors)]
    return found


def versions(tags: list[str]) -> dict:
    """What the version picker lists: `next`, then each minor, newest first, and the one the root holds."""
    minors = newest_by_minor(tags)
    released = [minor for minor in ordered_minors(minors) if minors[minor]["released"]]
    listed = [{"name": "next", "path": f"{NEXT}/"}]
    listed += [{"name": minor, "path": f"/{minor}/"} for minor in ordered_minors(minors)]
    return {"versions": listed, "root": released[0] if released else "next"}


def tags() -> list[str]:
    """Every tag git lists."""
    listed = subprocess.run(["git", "tag", "--list", "v*"], capture_output=True, text=True, check=True)
    return [line for line in listed.stdout.splitlines() if line]


def main(argv: list[str]) -> int:
    if argv not in (["plan"], ["versions"]):
        print(__doc__, file=sys.stderr)
        return 2
    found = tags()
    print(json.dumps(copies(found) if argv == ["plan"] else versions(found)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
