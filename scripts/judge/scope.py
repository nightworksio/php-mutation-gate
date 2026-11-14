#!/usr/bin/env python3
"""The judge's tree: the gate's own, with every class of it under a namespace of its own (ADR-0140).

Usage:
  python3 scripts/judge/scope.py <tree>   rewrite a copy of the gate's tree in place

The judge judges the gate's own pull requests from a build of another commit,
beside the pull request's own copy of the gate, which the project's autoloader
declares. Two copies of one class cannot coexist, so every name of the gate's
namespaces is written under `MutationGateJudge\\`, in the code and in every
string that names one, the code-writing templates included. The scoped
dependencies take a prefix of their own, and the archive an alias and a file
of its own, so neither meets the pull request's PHAR in one process. Each
rewrite that names a file must change it, so a moved anchor stops the build
rather than building a judge that is not scoped.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

PREFIX = "MutationGateJudge"

NAMESPACE = re.compile(rb"NightWorksIO(\\+)MutationGate")
"""A name of the gate's namespaces, its separators as written: one backslash in code, more in a string."""

TREES = ("src", "bin", "plugins", "resources")
"""The directories whose every file is rewritten: the archive's code, scripts and resources."""

MANIFESTS = ("composer.json", "scoper.inc.php")
"""The files outside them whose names of the gate's namespaces are rewritten: the autoload and the scoping."""

ANCHORED = (
    ("scoper.inc.php", b"'NightWorksIOMutationGateScoped'", b"'MutationGateJudgeScoped'"),
    ("box.json.dist", b"build/mutation-gate.phar", b"build/mutation-gate-judge.phar"),
    ("src/Core/OwnArchive.php", b"'mutation-gate.phar'", b"'mutation-gate-judge.phar'"),
    ("resources/phar-stub.php", b"mutation-gate.phar", b"mutation-gate-judge.phar"),
)
"""Each rewrite of one file: the scoped dependencies' prefix, the archive's file and its alias."""


class Unanchored(ValueError):
    """A rewrite whose text its file no longer holds."""


def scoped(text: bytes) -> bytes:
    """A file's text, every name of the gate's namespaces under the judge's."""
    return NAMESPACE.sub(lambda name: PREFIX.encode() + name.group(1) + name.group(0), text)


def anchored(text: bytes, file: str, before: bytes, after: bytes) -> bytes:
    """A file's text with one rewrite applied; refused where the file holds none of it."""
    if before not in text:
        raise Unanchored(f"{file} no longer holds {before.decode()}; update scripts/judge/scope.py")
    return text.replace(before, after)


def files(tree: Path) -> list[Path]:
    """Every file the namespaces are rewritten in."""
    found = [path for name in TREES for path in sorted((tree / name).rglob("*")) if path.is_file()]
    return found + [tree / name for name in MANIFESTS if (tree / name).is_file()]


def scope(tree: Path) -> int:
    """Rewrites the tree in place; how many files changed."""
    changed = 0
    for path in files(tree):
        text = path.read_bytes()
        written = scoped(text)
        if written != text:
            path.write_bytes(written)
            changed += 1
    for name, before, after in ANCHORED:
        path = tree / name
        path.write_bytes(anchored(path.read_bytes(), name, before, after))
    return changed


def main(argv: list[str]) -> int:
    if len(argv) != 1 or not Path(argv[0]).is_dir():
        print(__doc__, file=sys.stderr)
        return 2
    try:
        print(f"Scoped {scope(Path(argv[0]))} files under {PREFIX}.")
    except Unanchored as unanchored:
        print(unanchored, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
