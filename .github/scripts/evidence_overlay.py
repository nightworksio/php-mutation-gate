#!/usr/bin/env python3
"""A run's evidence as one directory: an earlier attempt's, overlaid by this one's.

Usage: evidence_overlay.py <earlier> <attempt> <gathered>

<earlier> holds what an earlier attempt of the run gathered into the artifact
`evidence`, and <attempt> what this attempt's jobs left; either may be
missing. Each file of <attempt> takes the place of the file of its name in
<earlier>, so a job run again speaks for itself and a job not run again keeps
what it said (ADR-0019). <gathered> holds the result. It exits 1, saying so,
where neither holds a file, as the run left no evidence at all.
"""
import shutil
import sys
from pathlib import Path

NONE = "No job of this run left evidence, in this attempt or an earlier one."


def overlaid(earlier: Path, attempt: Path) -> dict:
    """Each file of the gathered evidence, by its name, from the directory it is taken from."""
    files = {}

    for directory in (earlier, attempt):
        if directory.is_dir():
            for file in sorted(directory.rglob("*")):
                if file.is_file():
                    files[file.relative_to(directory).as_posix()] = file

    return files


def main(argv: list) -> int:
    if len(argv) != 4:
        print(__doc__.strip().splitlines()[2], file=sys.stderr)
        return 2

    earlier, attempt, gathered = (Path(argument) for argument in argv[1:])
    files = overlaid(earlier, attempt)

    if not files:
        print(f"::error::{NONE}")
        return 1

    for name, source in files.items():
        target = gathered / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
