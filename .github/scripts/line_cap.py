#!/usr/bin/env python3
"""No tracked file is longer than a reviewer can read.

Every tracked file is counted, whoever or whatever wrote it. A generated file is
read as often as a written one: in a diff, by the reader of a vendored copy, and by
the generator of the next thing downstream. One that outgrows a reader has to be
split where it is generated.

Two kinds of file are not counted, and the list is written here and nowhere else:
the lockfiles a package manager writes and nobody reads line by line, and the
images and fonts that are binary or, in an SVG's case, drawn rather than read.

A file a repository's `scripts/generated.py` registers with `sources` is read
through those sources, so it is held to the cap through them: it passes where every
source is tracked and within the cap, and is refused naming a source that is not.
The registry is read as data, never run.

Usage, from the root of the repository being checked::

    python3 .github/scripts/line_cap.py --root .

Exit 0 = every counted file is within the cap, 1 = at least one is over it, or the
tree could not be listed.
"""

from __future__ import annotations

import argparse
import ast
import os
import pathlib
import subprocess
import sys

#: The most lines a tracked file may hold.
CAP = 1000

#: Lockfiles, by the name a package manager gives them. `*.lock` covers
#: `Cargo.lock`, `composer.lock` and `uv.lock`; npm's is the one that ends otherwise.
LOCK_SUFFIX = ".lock"
LOCKFILES = frozenset({"package-lock.json"})

#: Images and fonts, by extension.
ASSETS = frozenset(
    {
        ".apng",
        ".avif",
        ".bmp",
        ".gif",
        ".ico",
        ".jpeg",
        ".jpg",
        ".png",
        ".svg",
        ".tif",
        ".tiff",
        ".webp",
        ".eot",
        ".otf",
        ".ttf",
        ".woff",
        ".woff2",
    }
)

#: The `git ls-files --stage` modes of what is not a file's own text: a submodule
#: and a symbolic link. Neither has lines of its own to count.
NOT_TEXT = frozenset({"160000", "120000"})


#: Where a repository registers the files it generates, relative to its root.
REGISTRY = "scripts/generated.py"


def registered(root: pathlib.Path) -> dict[str, tuple[str, ...]]:
    """Every generated file a repository registers, to the sources it is read through.

    A file registered without sources maps to none, and is counted like any other.

    Read from the registry's syntax rather than by importing it: the check runs
    over trees it was not written in, and running one of their scripts to learn
    which of their files to count would run whatever the script does. An entry
    whose fields are not written as literals registers nothing.
    """
    registry = root / REGISTRY
    if not registry.is_file():
        return {}
    held: dict[str, tuple[str, ...]] = {}
    for node in ast.walk(ast.parse(registry.read_text(encoding="utf-8"))):
        if not (isinstance(node, ast.Call) and getattr(node.func, "id", None) == "Generated"):
            continue
        fields = {}
        for keyword in node.keywords:
            try:
                fields[keyword.arg] = ast.literal_eval(keyword.value)
            except ValueError:
                continue
        sources = tuple(fields.get("sources") or ())
        held.update(dict.fromkeys(fields.get("paths") or (), sources))
    return held


def exempt(path: str) -> bool:
    """Whether `path` is a lockfile, an image or a font."""
    name = os.path.basename(path)
    if name in LOCKFILES or name.endswith(LOCK_SUFFIX):
        return True
    return os.path.splitext(name)[1].lower() in ASSETS


def lines_in(data: bytes) -> int:
    """How many lines `data` holds, the last one counted whether it ends or not."""
    count = data.count(b"\n")
    if data and not data.endswith(b"\n"):
        count += 1
    return count


def tracked(root: pathlib.Path) -> list[str] | None:
    """Every tracked path whose own text can be counted, or None where git could not say.

    A listing that did not happen is not a listing of nothing, and the caller
    refuses rather than reporting a clean tree.
    """
    # The root is the working directory rather than an argument, so no path given on
    # the command line reaches git's own arguments.
    try:
        listed = subprocess.run(
            ["git", "ls-files", "--stage", "-z"],
            cwd=root,
            capture_output=True,
            check=False,
        )
    except OSError:
        return None
    if listed.returncode != 0:
        return None
    paths = []
    for entry in listed.stdout.decode("utf-8", "surrogateescape").split("\0"):
        if not entry:
            continue
        meta, path = entry.split("\t", 1)
        if meta.split(" ", 1)[0] not in NOT_TEXT:
            paths.append(path)
    return paths


def counted(root: pathlib.Path, path: str) -> int | None:
    """How many lines a tracked path holds, or None where it is not a file here."""
    target = root / path
    if not target.is_file():
        return None
    return lines_in(target.read_bytes())


def unheld(root: pathlib.Path, sources: tuple[str, ...], tracked: set[str]) -> list[str]:
    """Why each source a generated file is read through does not hold it to the cap."""
    reasons = []
    for source in sources:
        held = counted(root, source) if source in tracked else None
        if held is None:
            reasons.append(f"it is generated from {source}, which is not a tracked file")
        elif held > CAP:
            reasons.append(f"it is generated from {source}, which is {held} lines")
    return reasons


def over(root: pathlib.Path, paths: list[str]) -> list[tuple[str, int, str]]:
    """Every counted path over the cap, longest first: the path, its count, and why.

    A registered generated file over the cap is held to its sources: it is named
    only where a source is missing or is itself over the cap, and the reason says
    which source.
    """
    found = []
    tracked = set(paths)
    through = registered(root)
    for path in paths:
        count = None if exempt(path) else counted(root, path)
        if count is None or count <= CAP:
            continue
        sources = through.get(path)
        reasons = unheld(root, sources, tracked) if sources else [""]
        found.extend((path, count, why) for why in reasons)
    return sorted(found, key=lambda one: (-one[1], one[0], one[2]))


def refusal(path: str, count: int, why: str = "") -> str:
    """What one file over the cap is told."""
    if why:
        return (
            f"{path} is {count} lines, over the cap of {CAP}, and is held to its sources: "
            f"{why}. Split the source."
        )
    return (
        f"{path} is {count} lines, over the cap of {CAP}. Split it by what it holds; "
        f"a generated file is split where it is generated."
    )


def main(argv: list[str] | None = None, out=sys.stdout) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--root", type=pathlib.Path, default=pathlib.Path("."))
    args = parser.parse_args(argv)

    paths = tracked(args.root)
    if paths is None:
        print(f"::error::git could not list the files tracked under {args.root}.", file=out)
        return 1
    if not paths:
        print(
            f"::error::{args.root} tracks no file this could count, so nothing was "
            "measured — a check that read nothing has not passed.",
            file=out,
        )
        return 1

    found = over(args.root, paths)
    for path, count, why in found:
        print(f"::error file={path}::{refusal(path, count, why)}", file=out)
    if found:
        named = len({path for path, _, _ in found})
        print(f"{named} of {len(paths)} tracked file(s) are over {CAP} lines.", file=out)
        return 1
    print(f"{len(paths)} tracked file(s), none over {CAP} lines.", file=out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
