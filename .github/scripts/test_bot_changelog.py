"""The changelog preview, by main's cliff.toml: python3 .github/scripts/unit.py"""
import unittest

import bot_changelog
import fake_github

CLIFF = (fake_github.ROOT / "cliff.toml").read_text(encoding="utf-8")


class Groups(unittest.TestCase):
    def test_the_groups_are_cliff_toml_s_with_their_sort_keys_left_out(self):
        breaking, by_message = bot_changelog.groups(CLIFF)

        self.assertEqual(breaking, "Breaking")
        self.assertEqual([name for _, name in by_message], ["Added", "Fixed", "Changed"])


class Preview(unittest.TestCase):
    def test_a_feature_is_added_with_its_scope_in_bold_and_its_first_letter_raised(self):
        self.assertEqual(bot_changelog.preview("feat(pest): a faster worker", "", CLIFF), ("Added", "- **pest:** A faster worker"))

    def test_a_fix_and_a_performance_change_have_their_sections(self):
        self.assertEqual(bot_changelog.preview("fix: the floor", "", CLIFF), ("Fixed", "- The floor"))
        self.assertEqual(bot_changelog.preview("perf: less", "", CLIFF), ("Changed", "- Less"))

    def test_what_the_changelog_leaves_out_has_no_section(self):
        self.assertIsNone(bot_changelog.preview("docs: words", "", CLIFF))
        self.assertIsNone(bot_changelog.preview("not conventional", "", CLIFF))

    def test_a_breaking_change_quotes_its_migration(self):
        body = "Why.\r\n\r\n## Migration\r\n\r\nRename the key.\r\n\r\nThen rerun.\r\n\r\n## Notes\r\n\r\nNone."

        section, line = bot_changelog.preview("feat(config)!: one key", body, CLIFF)

        self.assertEqual(section, "Breaking")
        self.assertEqual(line, "- **config:** One key\n\n  > Rename the key.\n  >\n  > Then rerun.")

    def test_a_breaking_footer_makes_any_type_breaking(self):
        self.assertEqual(bot_changelog.preview("docs: x", "BREAKING CHANGE: y", CLIFF), ("Breaking", "- X"))


if __name__ == "__main__":
    unittest.main()
