"""The deciding half of release.py: python3 -m unittest discover .github/scripts"""
import unittest

import release

CHANGELOG = """# Changelog

Every release.

## [1.1.0] - 2026-10-01

### Added

- **pest:** Something (#70)

## [1.0.0] - 2026-09-30

### Added

- The first (#1)
"""


class Level(unittest.TestCase):
    def test_a_feature_is_a_minor_and_a_fix_or_perf_a_patch(self):
        self.assertEqual(release.level("feat(pest): judge more (#1)"), "minor")
        self.assertEqual(release.level("fix: a thing (#2)"), "patch")
        self.assertEqual(release.level("perf(php): faster (#3)"), "patch")

    def test_a_bang_or_a_breaking_footer_is_a_major(self):
        self.assertEqual(release.level("refactor(config)!: rename (#4)"), "major")
        self.assertEqual(release.level("fix: moved (#5)\n\nBREAKING CHANGE: the output moved"), "major")

    def test_every_other_commit_asks_nothing(self):
        for message in ("docs: words (#6)", "chore: tidy", "refactor: inside", "Merge branch 'x'"):
            self.assertIsNone(release.level(message))


class NextVersion(unittest.TestCase):
    def test_the_highest_level_since_the_last_tag_moves_the_version(self):
        self.assertEqual(release.next_version("v1.2.3", ["fix: a", "docs: b"], ""), "1.2.4")
        self.assertEqual(release.next_version("v1.2.3", ["fix: a", "feat: b"], ""), "1.3.0")
        self.assertEqual(release.next_version("v1.2.3", ["feat!: a", "fix: b"], ""), "2.0.0")

    def test_below_one_a_breaking_change_is_a_minor(self):
        self.assertEqual(release.next_version("v0.4.1", ["feat!: a"], ""), "0.5.0")

    def test_a_given_version_wins_where_it_comes_after_the_last(self):
        self.assertEqual(release.next_version("v0.9.0", ["fix: a"], "1.0.0"), "1.0.0")
        self.assertEqual(release.next_version(None, [], "v0.1.0"), "0.1.0")

    def test_refuses_what_cannot_be_released(self):
        cases = [("v1.0.0", ["docs: a"], ""), (None, ["feat: a"], ""), ("v1.2.0", [], "1.1.9"), ("v1.2.0", [], "2.0")]
        for last, messages, given in cases:
            with self.assertRaises(release.Refused):
                release.next_version(last, messages, given)


class Changelog(unittest.TestCase):
    def test_puts_a_section_above_every_earlier_one_below_the_header(self):
        written = release.prepend(CHANGELOG, "## [1.2.0] - 2026-10-02\n\n### Fixed\n\n- A fix (#71)\n")
        self.assertTrue(written.startswith("# Changelog\n\nEvery release.\n\n## [1.2.0] - 2026-10-02\n"))
        self.assertIn("- A fix (#71)\n\n## [1.1.0] - 2026-10-01\n", written)

    def test_puts_the_first_section_below_the_header(self):
        written = release.prepend("# Changelog\n\nEvery release.\n", "## [1.0.0] - 2026-09-30\n\n- One\n")
        self.assertEqual(written, "# Changelog\n\nEvery release.\n\n## [1.0.0] - 2026-09-30\n\n- One\n")

    def test_the_notes_are_the_tags_section_without_its_heading(self):
        self.assertEqual(release.notes(CHANGELOG, "v1.1.0"), "### Added\n\n- **pest:** Something (#70)\n")
        self.assertEqual(release.notes(CHANGELOG, "v1.0.0"), "### Added\n\n- The first (#1)\n")

    def test_refuses_a_tag_without_a_section(self):
        with self.assertRaises(release.Refused):
            release.notes(CHANGELOG, "v1.0.1")
        with self.assertRaises(release.Refused):
            release.notes("## [2.0.0] - 2026-10-03\n\n## [1.0.0]\n", "v2.0.0")


class Line(unittest.TestCase):
    def test_a_release_line_is_the_major_and_minor_while_the_major_is_0(self):
        self.assertEqual(release.line("v0.1.0"), "0.1")
        self.assertEqual(release.line("0.1.4"), "0.1")
        self.assertEqual(release.line("v1.4.0"), "1")
        with self.assertRaises(release.Refused):
            release.line("v0.1")

    def test_a_tag_of_the_actions_line_passes_and_another_is_refused(self):
        self.assertIsNone(release.line_refusal("v0.1.0", "0.1"))
        self.assertIsNone(release.line_refusal("v0.1.3", "0.1"))
        self.assertIn("LINE is 0.1", release.line_refusal("v0.2.0", "0.1"))
        self.assertIn("LINE is 0.1", release.line_refusal("v1.0.0", "0.1"))

    def test_moves_the_one_line(self):
        self.assertEqual(release.with_line('A = 1\nLINE = "0.1"\n', "0.2"), 'A = 1\nLINE = "0.2"\n')
        self.assertEqual(release.with_line('LINE = "0.9"\n', "1"), 'LINE = "1"\n')
        with self.assertRaises(release.Refused):
            release.with_line("A = 1\n", "0.2")


if __name__ == "__main__":
    unittest.main()
