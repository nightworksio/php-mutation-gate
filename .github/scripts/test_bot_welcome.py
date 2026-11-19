"""The welcome sweep: python3 .github/scripts/unit.py"""
import unittest

import bot_sticky
import bot_tables
import bot_welcome
import fake_github

TABLES = bot_tables.Tables(fake_github.ROOT)
WELCOMED = TABLES.config["checklist"]["welcomed"]
SHA = "c" * 40
OLDER = "b" * 40


def pull(number: int = 7, **changes) -> dict:
    return {
        "number": number,
        "state": "open",
        "user": {"type": "User"},
        "author_association": "FIRST_TIME_CONTRIBUTOR",
        "head": {"sha": SHA},
        **changes,
    }


def sticky(body: str, comment_id: int = 3) -> dict:
    return {"id": comment_id, "user": {"type": "Bot"}, "body": body}


class Deciding(unittest.TestCase):
    def test_a_person_s_open_pull_request_whose_author_is_welcomed_is_one_to_welcome(self):
        self.assertTrue(bot_welcome.welcomes(pull(), WELCOMED))
        for why, changes in {
            "closed": {"state": "closed"},
            "a bot's": {"user": {"type": "Bot"}},
            "no author": {"user": None},
            "a member's": {"author_association": "MEMBER"},
        }.items():
            with self.subTest(why):
                self.assertFalse(bot_welcome.welcomes(pull(**changes), WELCOMED))

    def test_the_head_is_marked_where_there_is_no_comment_or_its_welcome_names_an_older_head(self):
        self.assertEqual(bot_welcome.head_to_mark(pull(), None), SHA)
        self.assertEqual(bot_welcome.head_to_mark(pull(), sticky(bot_sticky.opening(bot_sticky.welcome_mark(OLDER), "L"))), SHA)
        self.assertEqual(bot_welcome.head_to_mark(pull(), sticky(bot_sticky.opening(bot_sticky.welcome_mark(SHA), "L"))), "")
        self.assertEqual(bot_welcome.head_to_mark(pull(), sticky(f"{bot_sticky.STICKY}\n### Every gate passed.")), "")

    def test_a_head_github_would_not_send_stops_it(self):
        for head in (None, {"sha": "abc"}, {"sha": 5}):
            with self.subTest(head), self.assertRaises(ValueError):
                bot_welcome.head_to_mark(pull(head=head), None)

    def test_what_find_wrote_is_validated_again(self):
        self.assertEqual(bot_welcome.parse_pulls("7,12", 100), [7, 12])
        self.assertEqual(bot_welcome.parse_pulls("", 100), [])
        for text in ("7,x", "0", "7;8", "1,2,3"):
            with self.subTest(text), self.assertRaises(ValueError):
                bot_welcome.parse_pulls(text, 2)

    def test_a_number_github_would_not_send_stops_it(self):
        self.assertEqual(bot_welcome.number_of(pull(12)), 12)
        for number in (None, "7", 0, -1, True):
            with self.subTest(number), self.assertRaises(ValueError):
                bot_welcome.number_of(pull(number))


class Find(unittest.TestCase):
    def test_it_names_the_pull_requests_to_welcome_by_number(self):
        fake = fake_github.GitHub({
            ("GET", "pulls"): [
                pull(7),
                pull(8, author_association="MEMBER"),
                pull(9),
                pull(10),
            ],
            ("GET", "issues/7/comments"): [],
            ("GET", "issues/9/comments"): [sticky(f"{bot_sticky.STICKY}\n### Every gate passed.")],
            ("GET", "issues/10/comments"): [sticky(bot_sticky.opening(bot_sticky.welcome_mark(OLDER), "L"))],
        })
        with fake_github.running(fake) as output:
            bot_welcome.find(TABLES)
            written = fake_github.outputs(output)

        self.assertEqual(written, {"pulls": "7,10"})
        self.assertEqual(fake.asked[0][1], f"pulls?state=open&sort=created&direction=desc&per_page={TABLES.config['checklist']['sweep']['most_pulls']}")
        self.assertNotIn(("GET", "issues/8/comments", None, "GH_TOKEN"), fake.asked)
        self.assertEqual(fake.writes(), [])

    def test_it_reads_no_more_pull_requests_than_main_s_config_allows(self):
        fake = fake_github.GitHub({("GET", "pulls"): [pull(7), pull(8)], ("GET", "issues/7/comments"): []})
        TABLES.config["checklist"]["sweep"]["most_pulls"] = 1
        try:
            with fake_github.running(fake) as output:
                bot_welcome.find(TABLES)
                written = fake_github.outputs(output)
        finally:
            TABLES.config["checklist"]["sweep"]["most_pulls"] = 100

        self.assertEqual(written, {"pulls": "7"})


class Welcome(unittest.TestCase):
    def answers(self, the_pull: dict, comments: list) -> fake_github.GitHub:
        return fake_github.GitHub({
            ("GET", "pulls/7"): the_pull,
            ("GET", "issues/7/comments"): comments,
            ("POST", "issues/7/comments"): {},
            ("PATCH", "issues/comments/3"): {},
        })

    def test_the_app_opens_the_comment_with_the_welcome_s_mark(self):
        fake = self.answers(pull(), [])
        with fake_github.running(fake, {"PULLS": "7"}):
            bot_welcome.welcome(TABLES)

        (method, path, body, token), = fake.writes()
        self.assertEqual((method, path, token), ("POST", "issues/7/comments", "APP_TOKEN"))
        self.assertEqual(body["body"], f"{bot_sticky.STICKY}\n{bot_sticky.welcome_mark(SHA)}\n> **Welcome.** The checklist for this pull request on `ccccccc` is being written…\n")
        self.assertTrue(all(token == "GH_TOKEN" for method, _, _, token in fake.asked if method == "GET"))

    def test_the_app_marks_again_a_comment_whose_welcome_names_an_older_head(self):
        fake = self.answers(pull(), [sticky(bot_sticky.opening(bot_sticky.welcome_mark(OLDER), "L"))])
        with fake_github.running(fake, {"PULLS": "7"}):
            bot_welcome.welcome(TABLES)

        (method, path, body, token), = fake.writes()
        self.assertEqual((method, path, token), ("PATCH", "issues/comments/3", "APP_TOKEN"))
        self.assertEqual(bot_sticky.welcomed(body["body"]), SHA)

    def test_a_pull_request_no_longer_to_welcome_is_left_alone(self):
        for why, (the_pull, comments) in {
            "closed since": (pull(state="closed"), []),
            "welcomed since": (pull(), [sticky(bot_sticky.opening(bot_sticky.welcome_mark(SHA), "L"))]),
            "relayed since": (pull(), [sticky(f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(1, SHA)}")]),
        }.items():
            fake = self.answers(the_pull, comments)
            with self.subTest(why), fake_github.running(fake, {"PULLS": "7"}):
                bot_welcome.welcome(TABLES)
                self.assertEqual(fake.writes(), [])

    def test_numbers_the_find_step_would_not_write_stop_it_before_any_request(self):
        fake = fake_github.GitHub()
        with fake_github.running(fake, {"PULLS": "7,../8"}), self.assertRaises(ValueError):
            bot_welcome.welcome(TABLES)

        self.assertEqual(fake.asked, [])


class Main(unittest.TestCase):
    def test_it_runs_the_step_it_is_named_and_refuses_others(self):
        with fake_github.running(fake_github.GitHub(), {"PULLS": ""}):
            self.assertEqual(bot_welcome.main(["bot_welcome.py", "welcome"]), 0)
            self.assertEqual(bot_welcome.main(["bot_welcome.py", "nonsense"]), 2)
            self.assertEqual(bot_welcome.main(["bot_welcome.py"]), 2)


if __name__ == "__main__":
    unittest.main()
