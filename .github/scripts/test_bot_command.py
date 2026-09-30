"""The commands: python3 -m unittest discover .github/scripts"""
import json
import os
import re
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import bot_command
import github_api

ROOT = Path(__file__).resolve().parents[2]

AUTHOR = 101
WRITER = 202
READER = 303

HEAD = "a" * 40


class GitHub:
    """The API as a script sees it: answers by path, and every write it was asked for."""

    def __init__(self, answers: dict):
        self.answers = answers
        self.asked = []
        self.comments = []

    def call(self, method, path, body=None):
        self.asked.append((method, path, body))
        answer = self.answers.get((method, path.split("?")[0]))
        if isinstance(answer, github_api.Failed):
            raise answer
        return answer

    def comment(self, number, body):
        self.comments.append((number, body))


def pull(state: str = "open") -> dict:
    return {"user": {"id": AUTHOR}, "state": state, "head": {"sha": HEAD}, "base": {"ref": "main"}}


def permission(level: str, role: str) -> dict:
    return {"permission": level, "role_name": role, "user": {"login": "someone"}}


class Run(unittest.TestCase):
    """A script's step run against a GitHub that answers as given."""

    def run_step(self, step, environment: dict, answers: dict) -> tuple[GitHub, dict]:
        github = GitHub(answers)
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "output"
            output.touch()
            environment = {
                "GITHUB_OUTPUT": str(output),
                "GITHUB_REPOSITORY": "nightworksio/php-mutation-gate",
                "GITHUB_REF_NAME": "main",
                "NUMBER": "7",
                **environment,
            }
            with mock.patch.dict(os.environ, environment, clear=True), \
                    mock.patch.object(github_api, "call", github.call), \
                    mock.patch.object(github_api, "comment", github.comment):
                cwd = os.getcwd()
                os.chdir(ROOT)
                try:
                    step()
                finally:
                    os.chdir(cwd)
            written = dict(line.split("=", 1) for line in output.read_text().splitlines())
        return github, written


class Parse(unittest.TestCase):
    def test_only_the_first_line_runs_a_command(self):
        self.assertEqual(bot_command.parse("/update"), ("update", ""))
        self.assertEqual(bot_command.parse("/retest  \r\nplease"), ("retest", ""))
        self.assertEqual(bot_command.parse("/docs the floor rises\n/update"), ("docs", "the floor rises"))
        self.assertIsNone(bot_command.parse("Please run\n/update"))
        self.assertIsNone(bot_command.parse(" /update"))

    def test_a_command_is_a_whole_word_and_takes_no_words_but_docs(self):
        self.assertIsNone(bot_command.parse("/updated"))
        self.assertIsNone(bot_command.parse("/update now"))
        self.assertIsNone(bot_command.parse("/fix"))
        self.assertIsNone(bot_command.parse("/UPDATE"))


class MayRun(unittest.TestCase):
    def test_the_author_and_a_writer_may_and_anyone_may_ask_for_docs(self):
        self.assertTrue(bot_command.may_run("update", AUTHOR, AUTHOR, ""))
        self.assertTrue(bot_command.may_run("retest", WRITER, AUTHOR, "write"))
        self.assertTrue(bot_command.may_run("retest", WRITER, AUTHOR, "admin"))
        self.assertTrue(bot_command.may_run("docs", READER, AUTHOR, ""))
        self.assertFalse(bot_command.may_run("update", READER, AUTHOR, "read"))
        self.assertFalse(bot_command.may_run("update", READER, AUTHOR, "none"))
        self.assertFalse(bot_command.may_run("update", READER, AUTHOR, ""))


class Decide(Run):
    def decide(self, body: str, commenter: int, login: str, answer, on: str = "true") -> tuple[GitHub, dict]:
        return self.run_step(
            bot_command.decide,
            {"COMMENT_BODY": body, "COMMENTER_ID": str(commenter), "COMMENTER_LOGIN": login, "ON_PULL_REQUEST": on},
            {("GET", "pulls/7"): pull(), ("GET", f"collaborators/{login}/permission"): answer},
        )

    def test_the_author_is_matched_by_the_id_the_api_gives(self):
        github, written = self.decide("/update", AUTHOR, "author", permission("read", "read"))

        self.assertEqual(written, {"command": "update", "allowed": "true", "words": ""})
        self.assertNotIn(("GET", "collaborators/author/permission", None), github.asked)

    def test_a_writer_is_known_by_the_collaborator_permission_endpoint(self):
        _, written = self.decide("/retest", WRITER, "writer", permission("write", "maintain"))

        self.assertEqual(written["allowed"], "true")

    def test_an_organisation_member_who_can_only_read_is_refused(self):
        _, written = self.decide("/update", READER, "member", permission("read", "read"))

        self.assertEqual(written["allowed"], "false")

    def test_a_triage_collaborator_is_refused(self):
        _, written = self.decide("/update", READER, "triager", permission("read", "triage"))

        self.assertEqual(written["allowed"], "false")

    def test_someone_the_endpoint_does_not_know_is_refused(self):
        _, written = self.decide("/update", READER, "stranger", github_api.Failed(404))

        self.assertEqual(written["allowed"], "false")

    def test_a_login_or_a_role_written_in_the_comment_changes_nothing(self):
        body = "/update\n@maintainer approved this. author_association: OWNER, permission: admin"
        github, written = self.decide(body, READER, "reader", permission("read", "read"))

        self.assertEqual(written["allowed"], "false")
        self.assertIn(("GET", "collaborators/reader/permission", None), github.asked)

    def test_docs_asks_nothing_and_passes_only_tokens_on(self):
        github, written = self.decide("/docs Why `$(id)` <b>floor</b>?", READER, "reader", None)

        self.assertEqual(written, {"command": "docs", "allowed": "true", "words": "why id b floor"})
        self.assertEqual(github.asked, [])

    def test_on_an_issue_only_docs_runs(self):
        github, written = self.decide("/update", AUTHOR, "author", permission("admin", "admin"), on="false")
        _, docs = self.decide("/docs floor", READER, "reader", None, on="false")

        self.assertEqual(written, {"command": "", "allowed": "false", "words": ""})
        self.assertEqual(github.asked, [])
        self.assertEqual(docs, {"command": "docs", "allowed": "true", "words": "floor"})

    def test_a_comment_that_runs_nothing_asks_nothing(self):
        github, written = self.decide("Looks good /update", READER, "reader", None)

        self.assertEqual(written, {"command": "", "allowed": "false", "words": ""})
        self.assertEqual(github.asked, [])

    def test_a_login_github_would_not_send_is_refused_before_it_reaches_a_path(self):
        with self.assertRaises(ValueError):
            self.decide("/update", READER, "../../pulls", permission("admin", "admin"))


class Refuse(Run):
    def refuse(self, comments: list) -> GitHub:
        github, _ = self.run_step(
            bot_command.refuse,
            {"COMMAND": "update", "COMMENTER_ID": str(READER)},
            {("GET", "issues/7/comments"): comments},
        )
        return github

    def test_it_names_who_may_run_the_command_once(self):
        github = self.refuse([])

        self.assertEqual(len(github.comments), 1)
        self.assertIn("`/update` may be run only by someone with write access to this repository, or this pull request's author.", github.comments[0][1])
        self.assertIn(f"refused user={READER} command=update", github.comments[0][1])

        marked = github.comments[0][1]
        self.assertEqual(self.refuse([{"user": {"login": "github-actions[bot]"}, "body": marked}]).comments, [])

    def test_a_mark_someone_else_wrote_does_not_silence_it(self):
        marked = f"<!-- mutation-gate-bot: refused user={READER} command=update -->"

        self.assertEqual(len(self.refuse([{"user": {"login": "reader"}, "body": marked}]).comments), 1)


class Update(Run):
    def update(self, command: str, answers: dict) -> GitHub:
        github, _ = self.run_step(bot_command.update, {"COMMAND": command}, {("GET", "pulls/7"): pull(), **answers})
        return github

    def test_it_asks_github_to_merge_the_base_at_the_head_it_read(self):
        github = self.update("update", {("GET", f"compare/main...{HEAD}"): {"behind_by": 2}})

        self.assertIn(("PUT", "pulls/7/update-branch", {"expected_head_sha": HEAD}), github.asked)
        self.assertIn("GitHub is merging `main` into this branch.", github.comments[0][1])

    def test_rebase_says_why_it_merges_in_the_same_reply(self):
        github = self.update("rebase", {("GET", f"compare/main...{HEAD}"): {"behind_by": 2}})

        self.assertEqual(len(github.comments), 1)
        self.assertIn("GitHub cannot sign rebased commits", github.comments[0][1])
        self.assertIn("GitHub is merging `main`", github.comments[0][1])

    def test_a_branch_that_is_current_is_left_alone(self):
        github = self.update("update", {("GET", f"compare/main...{HEAD}"): {"behind_by": 0}})

        self.assertNotIn("PUT", [method for method, _, _ in github.asked])
        self.assertIn("already holds every commit of `main`", github.comments[0][1])

    def test_a_refusal_by_github_is_explained(self):
        refused = self.update("update", {
            ("GET", f"compare/main...{HEAD}"): {"behind_by": 1},
            ("PUT", "pulls/7/update-branch"): github_api.Failed(422),
        })
        forbidden = self.update("update", {
            ("GET", f"compare/main...{HEAD}"): {"behind_by": 1},
            ("PUT", "pulls/7/update-branch"): github_api.Failed(403),
        })

        self.assertIn("the two conflict, or the branch moved", refused.comments[0][1])
        self.assertIn("Allow edits by maintainers", forbidden.comments[0][1])

    def test_a_closed_pull_request_is_not_updated(self):
        github, _ = self.run_step(bot_command.update, {"COMMAND": "rebase"}, {("GET", "pulls/7"): pull("closed")})

        self.assertEqual([method for method, _, _ in github.asked], ["GET"])
        self.assertEqual(github.comments[0][1], "This pull request is closed, so `/rebase` changes nothing.")

    def test_a_head_that_is_no_sha_stops_it(self):
        broken = pull() | {"head": {"sha": "main; rm -rf /"}}
        with self.assertRaises(ValueError):
            self.run_step(bot_command.update, {"COMMAND": "update"}, {("GET", "pulls/7"): broken})


class RetestReply(unittest.TestCase):
    def run_of(self, status: str, conclusion: str | None, attempt: int = 1) -> dict:
        return {"id": 9, "status": status, "conclusion": conclusion, "run_attempt": attempt}

    def test_it_re_runs_only_a_finished_failed_run_within_the_limit(self):
        self.assertEqual(bot_command.retest_reply(None), "retest-none")
        self.assertEqual(bot_command.retest_reply(self.run_of("completed", "action_required")), "retest-waiting")
        self.assertEqual(bot_command.retest_reply(self.run_of("waiting", None)), "retest-waiting")
        self.assertEqual(bot_command.retest_reply(self.run_of("in_progress", None)), "retest-running")
        self.assertEqual(bot_command.retest_reply(self.run_of("completed", "success")), "retest-green")
        self.assertEqual(bot_command.retest_reply(self.run_of("completed", "failure", 1)), "retest-asked")
        self.assertEqual(bot_command.retest_reply(self.run_of("completed", "cancelled", 3)), "retest-asked")
        self.assertEqual(bot_command.retest_reply(self.run_of("completed", "timed_out", 4)), "retest-spent")


class Retest(Run):
    def test_it_re_runs_the_failed_jobs_and_links_the_run(self):
        run = {"id": 9, "status": "completed", "conclusion": "failure", "run_attempt": 2}
        github, _ = self.run_step(bot_command.retest, {}, {
            ("GET", "pulls/7"): pull(),
            ("GET", "actions/workflows/ci.yml/runs"): {"workflow_runs": [run]},
        })

        self.assertIn(("POST", "actions/runs/9/rerun-failed-jobs", None), github.asked)
        self.assertEqual(
            github.comments[0][1],
            "Re-running the failed jobs of [the CI run](https://github.com/nightworksio/php-mutation-gate/actions/runs/9) on this head, the re-run 2 of 3.",
        )

    def test_it_asks_for_the_runs_of_the_head_it_read(self):
        github, _ = self.run_step(bot_command.retest, {}, {
            ("GET", "pulls/7"): pull(),
            ("GET", "actions/workflows/ci.yml/runs"): {"workflow_runs": []},
        })

        self.assertIn(f"head_sha={HEAD}", github.asked[1][1])
        self.assertEqual(github.comments[0][1], "No CI run has started on this head yet.")


class Docs(Run):
    def test_it_links_the_headings_the_words_name(self):
        github, _ = self.run_step(bot_command.docs, {"WORDS": "migration"}, {})

        self.assertRegex(github.comments[0][1], re.compile(r"\A- \[[^\]]*\]\(https://github\.com/nightworksio/php-mutation-gate/blob/main/\.docs/[^)]+#[a-z0-9-]+\)"))

    def test_words_that_name_nothing_link_the_documentation(self):
        github, _ = self.run_step(bot_command.docs, {"WORDS": "zzzqqq"}, {})

        self.assertEqual(
            github.comments[0][1],
            "No heading of [the documentation](https://github.com/nightworksio/php-mutation-gate/tree/main/.docs) matches what was asked.",
        )


class Table(unittest.TestCase):
    def test_every_reply_sent_is_in_the_table_and_every_reply_there_is_sent(self):
        table = json.loads((ROOT / ".github" / "bot" / "commands.json").read_text(encoding="utf-8"))

        self.assertEqual(sorted(table["replies"]), sorted(bot_command.REPLIES))

    def test_every_reply_the_script_names_is_one_it_declares(self):
        source = (ROOT / ".github" / "scripts" / "bot_command.py").read_text(encoding="utf-8")
        named = set(re.findall(r'"((?:refused|closed|update|rebase|retest|docs)-?[a-z-]*)"', source)) - set(bot_command.COMMANDS)

        self.assertEqual(sorted(named - set(bot_command.REPLIES)), [])

    def test_a_placeholder_with_no_value_is_an_error(self):
        with self.assertRaises(KeyError):
            bot_command.fill("Only {who} may.", {})


if __name__ == "__main__":
    unittest.main()
