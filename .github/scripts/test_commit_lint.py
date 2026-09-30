"""The deciding half of commit_lint.py: python3 -m unittest discover .github/scripts"""
import unittest

import commit_lint


class Conventional(unittest.TestCase):
    def test_a_type_an_optional_scope_and_bang_then_something_to_say(self):
        self.assertTrue(commit_lint.conventional("feat(cli): refuse a config with no tree"))
        self.assertTrue(commit_lint.conventional("fix!: drop PHP 8.4"))
        self.assertTrue(commit_lint.conventional("Merge branch 'main' into feat/x"))
        self.assertFalse(commit_lint.conventional("Fix the thing"))
        self.assertFalse(commit_lint.conventional("feat(CLI): shouting scope"))

    def test_every_commit_git_named_whose_subject_is_not_conventional(self):
        out = "a" * 40 + "\x00feat: fine\n" + "b" * 40 + "\x00wip\n"

        self.assertEqual(commit_lint.unconventional(out), ["bbbbbbbb wip"])


class Findings(unittest.TestCase):
    def test_a_commit_is_named_by_its_short_sha_and_the_title_by_its_place(self):
        self.assertEqual(
            commit_lint.as_findings(["bbbbbbbb wip", "the pull request title: Fix it"]),
            [
                {"path": "", "line": 0, "rule": "conventional-subject", "detail": "bbbbbbbb"},
                {"path": "", "line": 0, "rule": "conventional-title", "detail": "title"},
            ],
        )


if __name__ == "__main__":
    unittest.main()
