"""The relay and the re-run of infrastructure failures: python3 .github/scripts/unit.py"""
import unittest

import bot_relay
import bot_sticky
import bot_tables
import fake_github

TABLES = bot_tables.Tables(fake_github.ROOT)
CONFIG = TABLES.config["rerun"]
WEB = fake_github.WEB
SHA = "f" * 40


def job(job_id: int, step: str | None, conclusion: str = "failure") -> dict:
    steps = [{"name": "Set up job", "conclusion": "success"}]
    if step is not None:
        steps.append({"name": step, "conclusion": "failure"})
    return {"id": job_id, "conclusion": conclusion, "steps": steps}


def run(**changes) -> dict:
    return {
        "id": 40,
        "event": "pull_request",
        "path": ".github/workflows/ci.yml",
        "status": "completed",
        "conclusion": "failure",
        "run_attempt": 1,
        "workflow_id": 70,
        "head_sha": SHA,
        "head_branch": "feature",
        "head_repository": {"owner": {"login": "someone"}},
        "pull_requests": [{"number": 7}],
        **changes,
    }


class Kind(unittest.TestCase):
    def test_a_failed_step_main_names_is_the_infrastructure_s(self):
        self.assertEqual(bot_relay.kind_of(job(1, "Run actions/checkout@abc"), CONFIG["steps"]), "checkout")
        self.assertEqual(bot_relay.kind_of(job(1, "Run shivammathur/setup-php@abc"), CONFIG["steps"]), "setup-php")
        self.assertEqual(bot_relay.kind_of({"id": 1, "steps": [{"name": "Set up job", "conclusion": "failure"}]}, CONFIG["steps"]), "runner")

    def test_a_job_that_lost_its_runner_failed_at_no_step(self):
        self.assertEqual(bot_relay.kind_of(job(1, None), CONFIG["steps"]), "runner")
        self.assertEqual(bot_relay.kind_of({"id": 1, "steps": None}, {"checkout": ["x"]}), "")

    def test_a_test_that_failed_is_no_infrastructure_failure(self):
        self.assertEqual(bot_relay.kind_of(job(1, "Run composer test"), CONFIG["steps"]), "")
        self.assertEqual(bot_relay.kind_of({"steps": [{"name": 5, "conclusion": "failure"}]}, CONFIG["steps"]), "")


class ToRerun(unittest.TestCase):
    def test_every_failed_job_at_the_infrastructure_is_re_run_on_the_head_s_first_run(self):
        jobs = [job(1, "Run actions/checkout@abc"), job(2, None), job(3, "Run tests", "success")]

        self.assertEqual(bot_relay.to_rerun(run(), 40, jobs, CONFIG), [(1, "checkout"), (2, "runner")])

    def test_nothing_is_re_run_where_one_failure_is_real_or_the_run_is_not_the_first(self):
        mixed = [job(1, "Run actions/checkout@abc"), job(2, "Run composer test")]
        infra = [job(1, "Run actions/checkout@abc")]

        self.assertEqual(bot_relay.to_rerun(run(), 40, mixed, CONFIG), [])
        self.assertEqual(bot_relay.to_rerun(run(), 39, infra, CONFIG), [])
        self.assertEqual(bot_relay.to_rerun(run(run_attempt=2), 40, infra, CONFIG), [])
        self.assertEqual(bot_relay.to_rerun(run(conclusion="success"), 40, infra, CONFIG), [])
        self.assertEqual(bot_relay.to_rerun(run(), 40, [], CONFIG), [])

    def test_what_rerun_wrote_is_validated_again(self):
        self.assertEqual(bot_relay.parse_reran("1:checkout,x:y,2:runner,3:BAD,"), [(1, "checkout"), (2, "runner")])


class Rerun(unittest.TestCase):
    def answers(self, the_run: dict, jobs: list[dict]) -> fake_github.GitHub:
        return fake_github.GitHub({
            ("GET", "actions/runs/40"): the_run,
            ("GET", "actions/workflows/70/runs"): {"workflow_runs": [{"id": 40}, {"id": 41}]},
            ("GET", "actions/runs/40/attempts/1/jobs"): {"jobs": jobs},
            ("POST", "actions/runs/40/rerun-failed-jobs"): None,
        })

    def test_it_re_runs_the_failed_jobs_and_names_them(self):
        fake = self.answers(run(), [job(5, "Run actions/download-artifact@abc")])
        with fake_github.running(fake, {"RUN_ID": "40"}) as output:
            bot_relay.rerun(TABLES)
            self.assertEqual(fake_github.outputs(output), {"reran": "5:artifact-download"})

        self.assertEqual(fake.writes(), [("POST", "actions/runs/40/rerun-failed-jobs", None, "GH_TOKEN")])

    def test_a_second_attempt_re_runs_nothing(self):
        fake = self.answers(run(run_attempt=2), [job(5, "Run actions/checkout@abc")])
        with fake_github.running(fake, {"RUN_ID": "40"}) as output:
            bot_relay.rerun(TABLES)
            self.assertEqual(fake_github.outputs(output), {"reran": ""})

        self.assertEqual(fake.writes(), [])

    def test_a_run_that_is_no_relayed_pull_request_s_re_runs_nothing(self):
        for the_run in (run(event="push"), run(path=".github/workflows/other.yml")):
            fake = self.answers(the_run, [])
            with self.subTest(the_run), fake_github.running(fake, {"RUN_ID": "40"}) as output:
                bot_relay.rerun(TABLES)
                self.assertEqual(fake_github.outputs(output), {"reran": ""})

    def test_a_run_id_or_a_head_github_would_not_send_stops_it(self):
        with fake_github.running(fake_github.GitHub(), {"RUN_ID": "40; x"}), self.assertRaises(ValueError):
            bot_relay.rerun(TABLES)
        fake = self.answers(run(head_sha="nope"), [])
        with fake_github.running(fake, {"RUN_ID": "40"}), self.assertRaises(ValueError):
            bot_relay.rerun(TABLES)

    def test_the_first_attempt_is_read_for_a_later_one(self):
        fake = self.answers(run(run_attempt=2), [job(5, "Run actions/checkout@abc")])
        fake.answers[("GET", "actions/runs/40/attempts/1")] = run()
        with fake_github.running(fake):
            self.assertEqual(bot_relay.reran_of(run(run_attempt=2), CONFIG), [(5, "checkout")])


class PullOf(unittest.TestCase):
    def test_the_pull_request_the_run_lists_is_the_one_whose_head_is_the_run_s(self):
        fake = fake_github.GitHub({
            ("GET", "pulls/7"): {"state": "open", "head": {"sha": SHA}},
        })
        with fake_github.running(fake):
            self.assertEqual(bot_relay.pull_of(run()), 7)

    def test_a_fork_s_pull_request_is_found_by_its_head_and_must_hold_the_run_s_head(self):
        fake = fake_github.GitHub({
            ("GET", "pulls/7"): {"state": "closed", "head": {"sha": SHA}},
            ("GET", "pulls"): [{"number": 8, "head": {"sha": "0" * 40}}, {"number": 9, "head": {"sha": SHA}}],
        })
        with fake_github.running(fake):
            self.assertEqual(bot_relay.pull_of(run()), 9)

        self.assertEqual(fake.asked[-1][1], "pulls?state=open&head=someone%3Afeature&per_page=10")

    def test_no_pull_request_holds_the_head(self):
        fake = fake_github.GitHub({("GET", "pulls"): [{"number": 8, "head": {"sha": "0" * 40}}]})
        with fake_github.running(fake):
            self.assertEqual(bot_relay.pull_of(run(pull_requests=[])), 0)

    def test_a_head_owner_or_branch_github_would_not_send_finds_nothing(self):
        with fake_github.running(fake_github.GitHub()):
            self.assertEqual(bot_relay.pull_of(run(pull_requests=None, head_repository={"owner": {"login": "-x y"}})), 0)
            self.assertEqual(bot_relay.pull_of(run(pull_requests=[], head_branch="a b")), 0)
            self.assertEqual(bot_relay.pull_of(run(pull_requests=[], head_repository=None, head_branch=None)), 0)


class Relay(unittest.TestCase):
    def answers(self, the_run: dict, comments: list) -> fake_github.GitHub:
        return fake_github.GitHub({
            ("GET", "actions/runs/40"): the_run,
            ("GET", "pulls/7"): {"state": "open", "head": {"sha": SHA}},
            ("GET", "issues/7/comments"): comments,
            ("POST", "issues/7/comments"): {},
            ("PATCH", "issues/comments/3"): {},
        })

    def test_the_app_sets_the_sticky_comment_to_its_explaining_state(self):
        sticky = {"id": 3, "user": {"type": "Bot"}, "body": f"{bot_sticky.STICKY}\n### Every gate passed."}
        fake = self.answers(run(), [sticky])
        with fake_github.running(fake, {"RUN_ID": "40"}):
            bot_relay.relay(TABLES)

        (method, path, body, token), = fake.writes()
        self.assertEqual((method, path, token), ("PATCH", "issues/comments/3", "APP_TOKEN"))
        self.assertEqual(
            body["body"],
            f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(40, SHA)}\n> **Explaining** [the ci run]({WEB}/actions/runs/40) on `fffffff`…\n\n### Every gate passed.\n",
        )

    def test_with_no_sticky_comment_the_app_opens_one(self):
        fake = self.answers(run(path=".github/workflows/pr.yml"), [])
        with fake_github.running(fake, {"RUN_ID": "40"}):
            bot_relay.relay(TABLES)

        (method, path, body, token), = fake.writes()
        self.assertEqual((method, path, token), ("POST", "issues/7/comments", "APP_TOKEN"))
        self.assertIn("[the pr run]", body["body"])

    def test_a_run_re_run_is_relayed_while_it_runs_again_and_one_still_running_is_not(self):
        fake = self.answers(run(status="queued"), [])
        with fake_github.running(fake, {"RUN_ID": "40", "RERAN": "5:checkout"}):
            bot_relay.relay(TABLES)
        self.assertEqual(len(fake.writes()), 1)

        fake = self.answers(run(status="queued"), [])
        with fake_github.running(fake, {"RUN_ID": "40"}):
            bot_relay.relay(TABLES)
        self.assertEqual(fake.writes(), [])

    def test_a_run_of_no_pull_request_writes_nothing(self):
        for the_run in (run(event="push"), run(pull_requests=[], head_repository=None)):
            fake = self.answers(the_run, [])
            with self.subTest(the_run), fake_github.running(fake, {"RUN_ID": "40"}):
                bot_relay.relay(TABLES)
                self.assertEqual(fake.writes(), [])


class Main(unittest.TestCase):
    def test_it_runs_the_step_it_is_named_and_refuses_others(self):
        fake = fake_github.GitHub({("GET", "actions/runs/40"): run(event="push")})
        with fake_github.running(fake, {"RUN_ID": "40"}):
            self.assertEqual(bot_relay.main(["bot_relay.py", "relay"]), 0)
            self.assertEqual(bot_relay.main(["bot_relay.py", "nonsense"]), 2)


if __name__ == "__main__":
    unittest.main()
