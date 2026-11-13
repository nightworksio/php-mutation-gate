#!/usr/bin/env python3
"""The lines llvm-cov's report counts unrun, from its text report with every instantiation shown.

Usage: unrun_lines.py <report.txt>

The report credits a function with its best instantiation alone, and a
function compiled into two test binaries is two instantiations of it, so a
line one copy ran and the best copy did not counts as unrun although the
merged view shows it run. Each such line is printed as `<file>:<line>
<function>`, then each line no copy ran.
"""
import re
import sys
from collections import defaultdict

FILE = re.compile(r"^(/.*\.rs):$")
INSTANTIATION = re.compile(r"^\s*\| ([^ 0-9|].*):$")
LINE = re.compile(r"^(\s*\|)?\s*(\d+)\|\s*([0-9.]+[kMG]?)\|")


def generic_free(name: str) -> str:
    """A function's name without the type arguments of its instantiation."""
    kept, depth, at = [], 0, 0
    while at < len(name):
        if depth == 0 and name.startswith("::<", at):
            depth, at = 1, at + 3
            continue
        if depth:
            depth += {"<": 1, ">": -1}.get(name[at], 0)
        else:
            kept.append(name[at])
        at += 1
    return "".join(kept)


def unrun(report: str) -> list[str]:
    """Each line the best copy of its function left unrun, then each line nothing ran, by file."""
    copies: dict[tuple[str, str], list[dict[int, bool]]] = defaultdict(list)
    copy: dict[int, bool] | None
    merged: dict[tuple[str, int], bool] = {}
    file, copy = "", None
    for line in report.split("\n"):
        named = FILE.match(line)
        if named:
            file, copy = named.group(1), None
            continue
        function = INSTANTIATION.match(line)
        if function and file:
            copy = {}
            copies[(file, generic_free(function.group(1)))].append(copy)
            continue
        counted = LINE.match(line)
        if not counted or not file:
            continue
        number, ran = int(counted.group(2)), counted.group(3) != "0"
        if counted.group(1):
            if copy is not None:
                copy[number] = ran
        else:
            merged[(file, number)] = ran
    found = []
    for (path, function), held in copies.items():
        if len(held) < 2:
            continue
        best = max(held, key=lambda lines: sum(lines.values()))
        union = sorted({number for lines in held for number, ran in lines.items() if ran})
        found += [f"{path}:{number} {function}" for number in union if not best.get(number, False)]
    return found + [f"{path}:{number}" for (path, number), ran in sorted(merged.items()) if not ran]


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(__doc__, file=sys.stderr)
        return 2
    with open(argv[1], encoding="utf-8") as report:
        print("\n".join(unrun(report.read())))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
