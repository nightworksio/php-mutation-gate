"""The judge's tree names every class of the gate's under a namespace of its own (ADR-0140)."""

from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("scope", ROOT / "scripts/judge/scope.py")
scope = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(scope)


def tree(files: dict[str, str]) -> Path:
    """A tree holding these files, each with its text."""
    root = Path(tempfile.mkdtemp())
    for name, text in files.items():
        (root / name).parent.mkdir(parents=True, exist_ok=True)
        (root / name).write_text(text, encoding="utf-8")
    return root


ANCHORS = {
    "scoper.inc.php": "<?php return ['prefix' => 'NightWorksIOMutationGateScoped'];",
    "box.json.dist": '{"output": "build/mutation-gate.phar"}',
    "src/Core/OwnArchive.php": "<?php const ALIAS = 'mutation-gate.phar';",
    "resources/phar-stub.php": "<?php Phar::mapPhar('mutation-gate.phar'); require 'phar://mutation-gate.phar/x';",
}


class Scoped(unittest.TestCase):
    def test_a_name_is_written_under_the_judge_whatever_its_separators(self):
        self.assertEqual(
            b"use MutationGateJudge\\NightWorksIO\\MutationGate\\Core\\Mutant;",
            scope.scoped(b"use NightWorksIO\\MutationGate\\Core\\Mutant;"),
        )
        self.assertEqual(
            b"'MutationGateJudge\\\\NightWorksIO\\\\MutationGateDefault\\\\Set'",
            scope.scoped(b"'NightWorksIO\\\\MutationGateDefault\\\\Set'"),
        )
        self.assertEqual(b"nightworksio/mutation-gate", scope.scoped(b"nightworksio/mutation-gate"))

    def test_the_trees_and_manifests_are_scoped_and_the_archive_takes_its_own_prefix_alias_and_file(self):
        root = tree({
            **ANCHORS,
            "src/Cli/Gate.php": "<?php namespace NightWorksIO\\MutationGate\\Cli;",
            "bin/mutation-gate": "<?php use NightWorksIO\\MutationGate\\Cli\\Gate;",
            "composer.json": '{"autoload": {"psr-4": {"NightWorksIO\\\\MutationGate\\\\": "src/"}}}',
            "tests/Unit/GateTest.php": "<?php use NightWorksIO\\MutationGate\\Cli\\Gate;",
        })

        scope.scope(root)

        self.assertEqual(
            "<?php namespace MutationGateJudge\\NightWorksIO\\MutationGate\\Cli;",
            (root / "src/Cli/Gate.php").read_text(encoding="utf-8"),
        )
        self.assertIn("MutationGateJudge\\NightWorksIO", (root / "bin/mutation-gate").read_text(encoding="utf-8"))
        self.assertIn("MutationGateJudge\\\\NightWorksIO", (root / "composer.json").read_text(encoding="utf-8"))
        self.assertEqual(
            "<?php use NightWorksIO\\MutationGate\\Cli\\Gate;",
            (root / "tests/Unit/GateTest.php").read_text(encoding="utf-8"),
        )
        self.assertIn("'MutationGateJudgeScoped'", (root / "scoper.inc.php").read_text(encoding="utf-8"))
        self.assertIn("build/mutation-gate-judge.phar", (root / "box.json.dist").read_text(encoding="utf-8"))
        self.assertIn("'mutation-gate-judge.phar'", (root / "src/Core/OwnArchive.php").read_text(encoding="utf-8"))
        self.assertEqual(
            "<?php Phar::mapPhar('mutation-gate-judge.phar'); require 'phar://mutation-gate-judge.phar/x';",
            (root / "resources/phar-stub.php").read_text(encoding="utf-8"),
        )

    def test_a_file_that_no_longer_holds_its_rewrite_stops_the_build(self):
        root = tree({**ANCHORS, "src/Core/OwnArchive.php": "<?php const ALIAS = 'moved.phar';"})

        with self.assertRaises(scope.Unanchored):
            scope.scope(root)
        self.assertEqual(1, scope.main([str(root)]))

    def test_it_asks_for_one_tree(self):
        self.assertEqual(2, scope.main([]))
        self.assertEqual(0, scope.main([str(tree(ANCHORS))]))


if __name__ == "__main__":
    unittest.main()
