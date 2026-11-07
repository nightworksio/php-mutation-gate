"""The sticky comment and its marks: python3 .github/scripts/unit.py"""
import unittest

import bot_sticky
import fake_github

SHA = "a" * 40
OTHER = "b" * 40


class Marks(unittest.TestCase):
    def test_the_relay_mark_is_read_back(self):
        body = f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(42, SHA)}\nrest"

        self.assertEqual(bot_sticky.relayed(body), (42, SHA))

    def test_a_mark_that_is_not_whole_on_its_line_is_no_mark(self):
        for body in ("", f"x {bot_sticky.relay_mark(42, SHA)}", f"<!-- mutation-gate-bot: run=0 sha={SHA} -->", "<!-- mutation-gate-bot: run=1 sha=abc -->"):
            self.assertIsNone(bot_sticky.relayed(body), body)

    def test_the_suggested_mark_names_its_head(self):
        self.assertEqual(bot_sticky.suggested(f"{bot_sticky.STICKY}\n{bot_sticky.suggested_mark(SHA)}"), SHA)
        self.assertEqual(bot_sticky.suggested("nothing"), "")


class Relaying(unittest.TestCase):
    def test_the_relay_replaces_its_last_mark_and_line_and_keeps_the_rest(self):
        before = f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(1, OTHER)}\n> **Explaining** old…\n\n{bot_sticky.suggested_mark(OTHER)}\n### Every gate passed."

        after = bot_sticky.relaying(before, bot_sticky.relay_mark(2, SHA), "> **Explaining** new…")

        self.assertEqual(after, f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(2, SHA)}\n> **Explaining** new…\n\n{bot_sticky.suggested_mark(OTHER)}\n### Every gate passed.\n")

    def test_a_new_comment_holds_only_the_mark_and_the_line(self):
        self.assertEqual(bot_sticky.relaying("", "M", "L"), f"{bot_sticky.STICKY}\nM\nL\n")


class Finding(unittest.TestCase):
    def test_only_a_bot_s_comment_that_starts_with_the_mark_is_the_sticky_one(self):
        self.assertTrue(bot_sticky.is_sticky({"user": {"type": "Bot"}, "body": f"{bot_sticky.STICKY}\nx"}))
        self.assertFalse(bot_sticky.is_sticky({"user": {"type": "User"}, "body": f"{bot_sticky.STICKY}\nx"}))
        self.assertFalse(bot_sticky.is_sticky({"user": {"type": "Bot"}, "body": f"x {bot_sticky.STICKY}"}))
        self.assertFalse(bot_sticky.is_sticky({"user": None, "body": None}))

    def test_the_oldest_sticky_comment_is_the_one(self):
        comments = [
            {"id": 1, "user": {"type": "User"}, "body": bot_sticky.STICKY},
            {"id": 2, "user": {"type": "Bot"}, "body": bot_sticky.STICKY},
            {"id": 3, "user": {"type": "Bot"}, "body": bot_sticky.STICKY},
        ]
        github = fake_github.GitHub({("GET", "issues/7/comments"): comments})
        with fake_github.running(github):
            self.assertEqual(bot_sticky.find(7)["id"], 2)

    def test_none_is_found_where_there_is_none(self):
        github = fake_github.GitHub({("GET", "issues/7/comments"): []})
        with fake_github.running(github):
            self.assertIsNone(bot_sticky.find(7))


if __name__ == "__main__":
    unittest.main()
