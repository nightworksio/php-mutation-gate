#!/usr/bin/env python3
"""The files this repository generates, each with the generator that writes it.

A generated file is read through what it is generated from, so the line cap
(`hygiene / lines`) holds it to those sources rather than counting it: a source
that outgrows the cap is refused instead. The cap reads `GENERATED` below as data,
never running this script, so every entry is written as literals.

Run:  python3 scripts/generated.py --list
"""

from __future__ import annotations

import argparse
import sys
from dataclasses import dataclass


@dataclass(frozen=True)
class Generated:
    """One generator, the files it writes, and the files they are read through."""

    generator: str
    """The script or command that writes the files, relative to the repository root."""

    recipe: str
    """The one command a contributor runs to rewrite the files."""

    paths: tuple[str, ...]
    """What it writes, relative to the repository root."""

    sources: tuple[str, ...]
    """The files it is generated from, relative to the repository root."""


GENERATED = (
    Generated(
        generator="scripts/bot-rules/pint.php",
        recipe="composer bot:rules",
        paths=(".github/bot/rules/pint.json",),
        sources=("scripts/bot-rules/pint.php", "pint.json"),
    ),
    Generated(
        generator="scripts/bot-rules/rector.php",
        recipe="composer bot:rules",
        paths=(".github/bot/rules/rector.json",),
        sources=("scripts/bot-rules/rector.php", "rector.php"),
    ),
    Generated(
        generator="scripts/report-schema.php",
        recipe="composer report:schema",
        paths=("resources/report.schema.json",),
        sources=("scripts/report-schema.php", "src/Core/Report/ReportSchema.php"),
    ),
    Generated(
        generator="src/Cli/Command/ConfigSchema.php",
        recipe="vendor/bin/mutation-gate config:schema > resources/mutation-gate.schema.json",
        paths=("resources/mutation-gate.schema.json",),
        sources=("src/Cli/Command/ConfigSchema.php", "src/Core/Config/Definition.php"),
    ),
)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--list", action="store_true", help="print each generated file and its recipe")
    if not parser.parse_args().list:
        parser.print_help()
        return 0
    for entry in GENERATED:
        for path in entry.paths:
            print(f"{path}\t{entry.recipe}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
