"""The explainer, the checklist and the welcome, against GitHub's answers: python3 .github/scripts/unit.py"""
import base64
import io
import json
import unittest
import zipfile

import bot_explain
import bot_sticky
import bot_tables
import fake_github
import github_api

WEB = fake_github.WEB
SHA = "d" * 40
TABLES = bot_tables.Tables(fake_github.ROOT)
PATH = "src/Adapter/Pest/Worker.php"
HEAD = "\n".join(["a", "b", "c"]) + "\n"
PATCH = f"diff --git a/{PATH} b/{PATH}\n@@ -2,1 +2,1 @@\n-b\n+B\n"


def zipped(files: dict[str, bytes]) -> bytes:
    data = io.BytesIO()
    with zipfile.ZipFile(data, "w") as opened:
        for name, content in files.items():
            opened.writestr(name, content)
    return data.getvalue()


def pull_answer(**changes) -> dict:
    return {
        "head": {"sha": SHA, "ref": "feature", "repo": {"full_name": fake_github.REPOSITORY}},
        "base": {"ref": "main"},
        "draft": False,
        "author_association": "MEMBER",
        "title": "fix(pest): the worker",
        "body": "Spec: 0004",
        **changes,
    }


def run_answer(run_id: int, path: str, **changes) -> dict:
    return {
        "id": run_id,
        "path": path,
        "status": "completed",
        "conclusion": "failure",
        "run_attempt": 1,
        "workflow_id": 70,
        "head_sha": SHA,
        "html_url": f"{WEB}/actions/runs/{run_id}",
        "pull_requests": [{"number": 7}],
        **changes,
    }


def github(overrides: dict | None = None) -> fake_github.GitHub:
    ci = run_answer(11, ".github/workflows/ci.yml")
    pr = run_answer(12, ".github/workflows/pr.yml", conclusion="success")
    findings = {"format": 1, "job": "checks", "tool": ["pint"], "findings": [{"tool": "pint", "path": PATH, "line": 0, "rule": "single_quote"}]}
    sticky = {"id": 99, "user": {"type": "Bot"}, "body": f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(11, SHA)}\n> **Explaining** …"}
    gate = {"id": 98, "user": {"type": "Bot"}, "body": "<!-- mutation-gate -->\nsurvivors", "html_url": f"{WEB}/pull/7#issuecomment-98"}
    answers = {
        ("GET", "pulls/7"): pull_answer(),
        ("GET", "pulls/7/files"): [
            {"filename": PATH, "status": "modified", "patch": "@@ -1,3 +1,3 @@\n a\n-x\n+b\n c"},
            {"filename": "gone.php", "status": "removed"},
            {"filename": "a b.php", "status": "modified"},
        ],
        ("GET", "actions/runs"): {"workflow_runs": [
            ci,
            pr,
            run_answer(5, ".github/workflows/ci.yml"),
            run_answer(13, ".github/workflows/ci.yml", pull_requests=[{"number": 8}]),
            run_answer(14, ".github/workflows/other.yml"),
        ]},
        ("GET", "actions/runs/11"): ci,
        ("GET", "actions/runs/11/jobs"): {"jobs": [
            {"name": "checks", "conclusion": "failure", "html_url": f"{WEB}/actions/runs/11/job/1"},
            {"name": "mutation / verdict", "conclusion": None, "html_url": f"{WEB}/actions/runs/11/job/2"},
        ]},
        ("GET", "actions/runs/12/jobs"): {"jobs": [{"name": "commitlint", "conclusion": "success", "html_url": f"{WEB}/actions/runs/12/job/3"}]},
        ("GET", "actions/runs/11/artifacts"): {"artifacts": [{"id": 21, "name": "evidence", "size_in_bytes": 100}]},
        ("GET", "actions/runs/12/artifacts"): {"artifacts": []},
        ("GET", f"contents/src/Adapter/Pest/Worker.php?ref={SHA}"): {"encoding": "base64", "size": len(HEAD), "content": base64.b64encode(HEAD.encode()).decode()},
        ("GET", "issues/7/comments"): [gate, sticky],
        ("POST", "pulls/7/reviews"): {},
        ("PATCH", "issues/comments/99"): {},
        ("POST", "issues/7/comments"): {},
        **(overrides or {}),
    }
    fake = fake_github.GitHub(answers)
    fake.archives[21] = zipped({"checks.json": json.dumps(findings).encode(), "checks.fix.patch": PATCH.encode()})
    return fake


def environment(body: str = "") -> dict:
    return {"NUMBER": "7", "COMMENT_BODY": body or f"{bot_sticky.STICKY}\n{bot_sticky.relay_mark(11, SHA)}"}


class Explain(unittest.TestCase):
    def test_it_writes_the_comment_over_the_relay_s_mark_and_posts_the_suggestions_once(self):
        fake = github()
        with fake_github.running(fake, environment()):
            bot_explain.explain(TABLES)

        writes = fake.writes()
        self.assertEqual([(method, path) for method, path, _, _ in writes], [("POST", "pulls/7/reviews"), ("PATCH", "issues/comments/99")])
        review = writes[0][2]
        self.assertEqual(review["commit_id"], SHA)
        self.assertEqual(review["comments"], [{"path": PATH, "line": 2, "side": "RIGHT", "body": "```suggestion\nB\n```"}])
        body = writes[1][2]["body"]
        self.assertTrue(body.startswith(f"{bot_sticky.STICKY}\n{bot_sticky.suggested_mark(SHA)}\n"))
        self.assertNotIn("run=11", body)
        self.assertIn("#### `checks` failed", body)
        self.assertIn(f"- `{PATH}`: `single_quote`.", body)
        self.assertTrue(all(token == "GH_TOKEN" for *_, token in fake.asked))

    def test_a_head_whose_fixes_were_suggested_gets_no_second_review(self):
        sticky = {"id": 99, "user": {"type": "Bot"}, "body": f"{bot_sticky.STICKY}\n{bot_sticky.suggested_mark(SHA)}\n{bot_sticky.relay_mark(11, SHA)}"}
        fake = github({("GET", "issues/7/comments"): [sticky]})
        with fake_github.running(fake, environment()):
            bot_explain.explain(TABLES)

        self.assertEqual([path for _, path, _, _ in fake.writes()], ["issues/comments/99"])
        self.assertIn(bot_sticky.suggested_mark(SHA), fake.writes()[0][2]["body"])

    def test_a_review_github_refuses_leaves_the_comment_written_and_the_head_unmarked(self):
        fake = github({("POST", "pulls/7/reviews"): github_api.Failed(422)})
        with fake_github.running(fake, environment()):
            bot_explain.explain(TABLES)

        body = fake.writes()[-1][2]["body"]
        self.assertNotIn(bot_sticky.suggested_mark(SHA), body)
        self.assertIn("#### `checks` failed", body)

    def test_any_other_error_posting_the_review_stops_it(self):
        fake = github({("POST", "pulls/7/reviews"): github_api.Failed(500)})
        with fake_github.running(fake, environment()), self.assertRaises(github_api.Failed):
            bot_explain.explain(TABLES)

    def test_the_gate_s_marker_inside_the_sticky_comment_is_not_the_gate_s_comment(self):
        sticky = {"id": 99, "user": {"type": "Bot"}, "body": f"{bot_sticky.STICKY}\n<!-- mutation-gate -->", "html_url": f"{WEB}/pull/7#issuecomment-99"}
        fake = github({("GET", "issues/7/comments"): [sticky]})
        with fake_github.running(fake):
            self.assertEqual(bot_explain.comments(7, TABLES), (sticky, ""))

    def test_a_suggestion_over_several_lines_names_where_it_starts(self):
        patch = f"diff --git a/{PATH} b/{PATH}\n@@ -1,2 +1,1 @@\n-a\n-b\n+ab\n"
        fake = github()
        fake.archives[21] = zipped({"checks.fix.patch": patch.encode()})
        with fake_github.running(fake, environment()):
            bot_explain.explain(TABLES)

        self.assertEqual(fake.writes()[0][2]["comments"][0]["start_line"], 1)

    def test_it_does_nothing_without_a_mark_or_for_a_run_that_is_not_the_head_s(self):
        cases = {
            "no mark": (environment("plain"), {}),
            "another head": (environment(), {("GET", "actions/runs/11"): run_answer(11, ".github/workflows/ci.yml", head_sha="e" * 40)}),
            "another workflow": (environment(), {("GET", "actions/runs/11"): run_answer(11, ".github/workflows/other.yml")}),
            "the head moved": (environment(), {("GET", "pulls/7"): pull_answer(head={"sha": "e" * 40, "ref": "feature"})}),
            "no sticky comment": (environment(), {("GET", "issues/7/comments"): []}),
        }
        for why, (values, overrides) in cases.items():
            fake = github({("GET", "actions/runs"): {"workflow_runs": []}, **overrides})
            with self.subTest(why), fake_github.running(fake, values):
                bot_explain.explain(TABLES)
                self.assertEqual(fake.writes(), [])


class Checklist(unittest.TestCase):
    def test_an_author_new_here_is_welcomed_in_a_new_comment(self):
        fake = github({
            ("GET", "pulls/7"): pull_answer(author_association="FIRST_TIME_CONTRIBUTOR", head={"sha": SHA, "ref": "x", "repo": None}),
            ("GET", "issues/7/comments"): [],
            ("GET", "actions/runs"): {"workflow_runs": [run_answer(11, ".github/workflows/ci.yml", status="completed", conclusion="action_required", pull_requests=[])]},
            ("GET", "actions/runs/11/artifacts"): {"artifacts": []},
        })
        with fake_github.running(fake, {"NUMBER": "7"}):
            bot_explain.checklist(TABLES)

        (method, path, body, _), = fake.writes()
        self.assertEqual((method, path), ("POST", "issues/7/comments"))
        self.assertIn("Thank you for your first pull request", body["body"])
        self.assertIn("CI waits for a maintainer to approve it.", body["body"])

    def test_a_member_s_pull_request_gets_no_comment_until_ci_relays_one(self):
        fake = github({("GET", "issues/7/comments"): [], ("GET", "actions/runs"): {"workflow_runs": []}})
        with fake_github.running(fake, {"NUMBER": "7"}):
            bot_explain.checklist(TABLES)

        self.assertEqual(fake.writes(), [])

    def test_an_existing_comment_is_brought_up_to_date(self):
        fake = github({("GET", "actions/runs"): {"workflow_runs": []}})
        with fake_github.running(fake, {"NUMBER": "7"}):
            bot_explain.checklist(TABLES)

        self.assertEqual([path for _, path, _, _ in fake.writes()], ["issues/comments/99"])


class Reading(unittest.TestCase):
    def test_a_value_github_would_not_send_stops_it(self):
        with self.assertRaises(ValueError):
            bot_explain.checked("x y", bot_explain.WORD)
        with fake_github.running(fake_github.GitHub()):
            for url in (3, "https://evil.example/x", f"{WEB}/pull/7 x"):
                with self.subTest(url), self.assertRaises(ValueError):
                    bot_explain.web(url)

    def test_text_is_cut_to_what_github_allows_and_anything_else_is_empty(self):
        self.assertEqual(bot_explain.text("abc", 2), "ab")
        self.assertEqual(bot_explain.text(None, 2), "")

    def test_a_head_file_the_bot_cannot_read_is_none(self):
        cases = {
            "gone": github_api.Failed(404),
            "a directory": [],
            "too large": {"encoding": "base64", "size": bot_explain.MOST_HEAD + 1, "content": ""},
            "not base64": {"encoding": "none", "size": 1},
            "not utf-8": {"encoding": "base64", "size": 1, "content": base64.b64encode(b"\xff").decode()},
            "not decodable": {"encoding": "base64", "size": 1, "content": "!"},
        }
        for why, answer in cases.items():
            fake = fake_github.GitHub({("GET", f"contents/a/b%20c?ref={SHA}"): answer})
            with self.subTest(why), fake_github.running(fake):
                self.assertIsNone(bot_explain.head_text("a/b c", SHA))

    def test_an_error_github_answers_reading_a_file_other_than_its_absence_still_reads_as_none(self):
        fake = fake_github.GitHub({("GET", f"contents/x?ref={SHA}"): github_api.Failed(500)})
        with fake_github.running(fake):
            self.assertIsNone(bot_explain.head_text("x", SHA))

    def test_a_run_of_another_pull_request_on_the_same_head_is_not_this_one_s(self):
        self.assertTrue(bot_explain.ours({"pull_requests": []}, 7))
        self.assertTrue(bot_explain.ours({"pull_requests": [{"number": 7}]}, 7))
        self.assertFalse(bot_explain.ours({"pull_requests": [{"number": 8}, "x"]}, 7))

    def test_the_jobs_a_run_re_ran_are_named_by_their_gate_or_as_a_job(self):
        run = run_answer(11, ".github/workflows/ci.yml", run_attempt=2, status="in_progress")
        failed = [
            {"id": 31, "conclusion": "failure", "steps": [{"name": "Run actions/checkout@abc", "conclusion": "failure"}]},
            {"id": 32, "conclusion": "failure", "steps": []},
        ]
        fake = fake_github.GitHub({
            ("GET", "actions/workflows/70/runs"): {"workflow_runs": [{"id": 11}]},
            ("GET", "actions/runs/11/attempts/1"): {**run, "run_attempt": 1, "conclusion": "failure"},
            ("GET", "actions/runs/11/attempts/1/jobs"): {"jobs": failed},
            ("GET", "actions/jobs/31"): {"name": "checks", "html_url": f"{WEB}/actions/runs/11/job/31"},
            ("GET", "actions/jobs/32"): {"name": 5, "html_url": f"{WEB}/actions/runs/11/job/32"},
        })
        with fake_github.running(fake):
            found = bot_explain.reran(run, TABLES)

        self.assertEqual([(job.name, job.kind) for job in found], [("checks", "checkout"), ("a job", "runner")])

    def test_a_first_attempt_that_finished_re_ran_nothing(self):
        with fake_github.running(fake_github.GitHub()):
            self.assertEqual(bot_explain.reran(run_answer(11, ".github/workflows/ci.yml"), TABLES), [])


class Main(unittest.TestCase):
    def test_it_runs_the_step_it_is_named_and_refuses_others(self):
        fake = github({("GET", "issues/7/comments"): [], ("GET", "actions/runs"): {"workflow_runs": []}})
        with fake_github.running(fake, {"NUMBER": "7"}):
            self.assertEqual(bot_explain.main(["bot_explain.py", "checklist"]), 0)
            self.assertEqual(bot_explain.main(["bot_explain.py", "nonsense"]), 2)
            self.assertEqual(bot_explain.main(["bot_explain.py"]), 2)


if __name__ == "__main__":
    unittest.main()
