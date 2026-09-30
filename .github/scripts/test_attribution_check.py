"""The deciding half of attribution_check.py: python3 -m unittest discover .github/scripts"""
import unittest

import attribution_check


class Attributed(unittest.TestCase):
    def test_a_trailer_or_a_badge_that_credits_an_assistant(self):
        self.assertEqual(len(attribution_check.attributed("x\n\nCo-authored-by: Claude <noreply@anthropic.com>\n")), 1)
        self.assertEqual(len(attribution_check.attributed("Generated with a tool\n")), 1)
        self.assertEqual(attribution_check.attributed("    Co-authored-by: Claude, quoted\n"), [])

    def test_every_commit_and_the_body_that_credit_one(self):
        out = "a" * 40 + "\x00feat: x\x00feat: x\n\nCo-authored-by: Copilot <c@github.com>\n\x01\n"

        said = attribution_check.problems(out, "Generated with Claude Code")

        self.assertEqual(len(said), 2)
        self.assertTrue(said[0].startswith("aaaaaaaa feat: x"))
        self.assertTrue(said[1].startswith("the pull request"))


class Findings(unittest.TestCase):
    def test_a_commit_is_named_by_its_short_sha_and_the_body_by_its_place(self):
        self.assertEqual(
            attribution_check.as_findings(["aaaaaaaa feat: x\n      Co-authored-by: Copilot", "the pull request\n      Generated with"]),
            [
                {"path": "", "line": 0, "rule": "credits-an-assistant", "detail": "aaaaaaaa"},
                {"path": "", "line": 0, "rule": "credits-an-assistant", "detail": "the pull request"},
            ],
        )


if __name__ == "__main__":
    unittest.main()
