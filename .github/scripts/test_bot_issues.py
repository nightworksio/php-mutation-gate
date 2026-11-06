"""Duplicate issues by message slug: python3 .github/scripts/unit.py"""
import unittest

import bot_issues
import bot_tables
import fake_github

TABLES = bot_tables.Tables(fake_github.ROOT)
WEB = fake_github.WEB
SITE = "https://nightworksio.github.io/php-mutation-gate/0.1/guide/troubleshooting/"
QUERY = f'repo:{fake_github.REPOSITORY} is:issue "no-tree"'


class Slugs(unittest.TestCase):
    def test_only_the_guide_s_slugs_are_kept_in_order_and_once(self):
        body = f"See {SITE}#no-tree and troubleshooting.md#two-runners, {SITE}#no-tree again, and troubleshooting#made-up."

        self.assertEqual(bot_issues.slugs_in(body, TABLES.slugs, 5), ["no-tree", "two-runners"])
        self.assertEqual(bot_issues.slugs_in(body, TABLES.slugs, 1), ["no-tree"])


class Reply(unittest.TestCase):
    def test_the_reply_lists_the_issues_and_links_each_section(self):
        said = bot_issues.reply([9, 4], ["no-tree"], TABLES, f"{WEB}/blob/main")

        self.assertTrue(said.startswith(f"{bot_issues.MARK}\nIssues that name the same message"))
        self.assertIn("- #9\n- #4", said)
        self.assertIn(f"[no-tree]({WEB}/blob/main/.docs/guide/troubleshooting.md#no-tree)", said)


class Run(unittest.TestCase):
    def github(self, comments: list) -> fake_github.GitHub:
        fake = fake_github.GitHub({
            ("GET", "issues/5/comments"): comments,
            ("POST", "issues/5/comments"): {},
            ("PATCH", "issues/comments/3"): {},
        })
        fake.found[QUERY] = [
            {"number": 5, "body": f"{SITE}#no-tree"},
            {"number": 2, "body": f"{SITE}#no-tree"},
            {"number": 4, "body": "words that say no tree"},
            {"number": "x", "body": f"{SITE}#no-tree"},
            {"number": 6, "body": None},
            {"number": 8, "body": f"{SITE}#no-tree"},
        ]
        return fake

    def test_it_replies_once_with_the_others_that_name_the_slug(self):
        fake = self.github([])
        with fake_github.running(fake, {"NUMBER": "5", "ISSUE_BODY": f"It said {SITE}#no-tree"}):
            self.assertEqual(bot_issues.main(["bot_issues.py"]), 0)

        (method, path, body, _), = fake.writes()
        self.assertEqual((method, path), ("POST", "issues/5/comments"))
        self.assertIn("- #8\n- #2", body["body"])

    def test_an_edit_updates_the_reply_it_already_wrote_and_leaves_it_where_nothing_changed(self):
        mine = {"id": 3, "user": {"login": "github-actions[bot]"}, "body": f"{bot_issues.MARK}\nold"}
        fake = self.github([{"id": 1, "user": {"login": "someone"}, "body": bot_issues.MARK}, mine])
        with fake_github.running(fake, {"NUMBER": "5", "ISSUE_BODY": f"{SITE}#no-tree"}):
            bot_issues.main(["bot_issues.py"])

        self.assertEqual([(method, path) for method, path, _, _ in fake.writes()], [("PATCH", "issues/comments/3")])

        mine["body"] = fake.writes()[0][2]["body"]
        fake = self.github([mine])
        with fake_github.running(fake, {"NUMBER": "5", "ISSUE_BODY": f"{SITE}#no-tree"}):
            bot_issues.main(["bot_issues.py"])

        self.assertEqual(fake.writes(), [])

    def test_a_body_with_no_slug_or_no_other_issue_gets_no_reply(self):
        fake = self.github([])
        fake.found = {}
        for body in ("nothing here", f"{SITE}#no-tree"):
            with self.subTest(body), fake_github.running(fake, {"NUMBER": "5", "ISSUE_BODY": body}):
                self.assertEqual(bot_issues.main(["bot_issues.py"]), 0)
        self.assertEqual(fake.writes(), [])

    def test_a_number_or_a_branch_github_would_not_send_stops_it(self):
        with fake_github.running(fake_github.GitHub(), {"NUMBER": "5 x"}), self.assertRaises(ValueError):
            bot_issues.main(["bot_issues.py"])
        fake = self.github([])
        with fake_github.running(fake, {"NUMBER": "5", "ISSUE_BODY": f"{SITE}#no-tree", "GITHUB_REF_NAME": "a b"}), self.assertRaises(ValueError):
            bot_issues.main(["bot_issues.py"])


if __name__ == "__main__":
    unittest.main()
