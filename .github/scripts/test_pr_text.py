"""The deciding half of pr_text.py, and .github/spec-map.json against the repository: python3 -m unittest discover .github/scripts"""
import json
import os
import subprocess
import unittest
from pathlib import Path

import pr_text

ROOT = Path(__file__).resolve().parents[2]


class Spec(unittest.TestCase):
    def test_every_number_a_spec_line_names(self):
        self.assertEqual(pr_text.spec_numbers("Why.\n\nSpec: 0007\nSpec: 0005, 0019\n"), ["0007", "0005", "0019"])
        self.assertEqual(pr_text.spec_numbers("A spec: 0007 in prose\n"), [])

    def test_a_number_no_decision_carries(self):
        self.assertEqual(pr_text.unknown_specs(["0007", "0012"], {"0007", "0019"}), ["0012"])


class Migration(unittest.TestCase):
    def test_a_breaking_title_needs_a_migration_section(self):
        self.assertTrue(pr_text.misses_migration("feat(cli)!: drop run --all", "Why.\n"))
        self.assertFalse(pr_text.misses_migration("feat(cli)!: drop run --all", "Why.\n\n## Migration\n\nUse run.\n"))
        self.assertFalse(pr_text.misses_migration("feat(cli): add run", "Why.\n"))


class Suggestion(unittest.TestCase):
    def test_the_type_is_what_every_changed_path_shares(self):
        self.assertEqual(pr_text.suggested_title([".docs/guide/a.md", ".docs/decisions/b.md"]), "docs: ")
        self.assertEqual(pr_text.suggested_title(["tests/Unit/ATest.php"]), "test: ")
        self.assertEqual(pr_text.suggested_title([".github/workflows/ci.yml"]), "ci: ")
        self.assertEqual(pr_text.suggested_title(["README.md", "tests/Unit/ATest.php"]), "<type>: ")

    def test_the_scope_is_the_directory_every_changed_source_file_shares(self):
        self.assertEqual(pr_text.suggested_title(["src/Adapter/Pest/Pest.php", "tests/Unit/Adapter/Pest/PestTest.php"]), "<type>(pest): ")
        self.assertEqual(pr_text.suggested_title(["src/Core/Reach/Reaching.php"]), "<type>(reach): ")
        self.assertEqual(pr_text.suggested_title(["src/Cli/Gate.php"]), "<type>(cli): ")
        self.assertEqual(pr_text.suggested_title(["src/Cli/Gate.php", "src/Core/Reach/Reaching.php"]), "<type>: ")


class Findings(unittest.TestCase):
    MAP = {"src/Core/Proof/**": ["0007"], "src/Core/Reach/**": ["0005"]}

    def test_it_fails_for_an_unknown_spec_and_a_missing_migration_and_only_suggests_the_rest(self):
        found = pr_text.findings("Fix!: it", "Spec: 0012\n", ["src/Core/Proof/Ledger.php"], {"0007"}, self.MAP)

        self.assertEqual([item["rule"] for item in found], ["unknown-spec", "suggest-title"])
        self.assertEqual([item["rule"] for item in pr_text.failing(found)], ["unknown-spec"])

    def test_it_suggests_the_mapped_decisions_where_the_body_names_none(self):
        found = pr_text.findings("fix(proof)!: key by the lock", "Why.\n", ["src/Core/Proof/Ledger.php", "src/Core/Reach/Reach.php"], {"0005", "0007"}, self.MAP)

        self.assertEqual(found, [
            {"path": "", "line": 0, "rule": "missing-migration", "detail": "title"},
            {"path": "", "line": 0, "rule": "suggest-spec", "detail": "0005, 0007"},
        ])


class Globs(unittest.TestCase):
    def test_a_glob_is_read_as_the_config_reads_one(self):
        self.assertTrue(pr_text.glob_pattern("src/Core/**").match("src/Core/Proof/Ledger.php"))
        self.assertTrue(pr_text.glob_pattern("*.md").match("README.md"))
        self.assertFalse(pr_text.glob_pattern("*.md").match(".docs/guide/a.md"))
        self.assertTrue(pr_text.glob_pattern("**/*.md").match(".docs/guide/a.md"))


class SpecMap(unittest.TestCase):
    def test_every_glob_matches_a_file_and_names_a_decision(self):
        spec_map = json.loads((ROOT / ".github" / "spec-map.json").read_text(encoding="utf-8"))["globs"]
        files = subprocess.run(["git", "ls-files"], cwd=ROOT, capture_output=True, text=True, check=True).stdout.splitlines()
        decisions = {name[:4] for name in os.listdir(ROOT / ".docs" / "decisions") if name[:4].isdigit()}
        wrong = []

        for glob, numbers in spec_map.items():
            if not any(pr_text.glob_pattern(glob).match(path) for path in files):
                wrong.append(f"{glob} matches no file")
            wrong.extend(f"{glob} names {number}, which is no decision" for number in numbers if number not in decisions)

        self.assertEqual(wrong, [])


if __name__ == "__main__":
    unittest.main()
