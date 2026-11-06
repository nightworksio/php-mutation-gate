"""`/fix`: where the App may write, which run it trusts, and what it commits: python3 .github/scripts/unit.py"""
import base64
import io
import json
import unittest
import zipfile

import bot_command
import bot_fix
import bot_patch
import bot_tables
import fake_github
import github_api

TABLES = bot_tables.Tables(fake_github.ROOT)
CONFIG = TABLES.config["fix"]
WEB = fake_github.WEB
SHA = "1" * 40
PATH = "src/Adapter/Pest/Worker.php"
HEAD = "a\nb\nc\n"
PATCH = f"diff --git a/{PATH} b/{PATH}\n@@ -2,1 +2,1 @@\n-b\n+B\n"
REPLIES = json.loads((fake_github.ROOT / ".github" / "bot" / "commands.json").read_text(encoding="utf-8"))["replies"]


def fixes(text: str = PATCH) -> dict[str, list[bot_patch.FilePatch]]:
    return {"checks": bot_patch.parse(text)}


class OffLimits(unittest.TestCase):
    def test_the_base_the_default_branch_and_a_guarded_branch_are_off_limits(self):
        protecting = CONFIG["protecting_rules"]

        self.assertFalse(bot_fix.off_limits("feature", "main", "main", False, [], protecting))
        self.assertFalse(bot_fix.off_limits("feature", "main", "main", False, [{"type": "required_signatures"}, "x"], protecting))
        self.assertTrue(bot_fix.off_limits("main", "release", "main", False, [], protecting))
        self.assertTrue(bot_fix.off_limits("release", "release", "main", False, [], protecting))
        self.assertTrue(bot_fix.off_limits("feature", "main", "main", True, [], protecting))
        self.assertTrue(bot_fix.off_limits("feature", "main", "main", False, [{"type": "update"}], protecting))


class OwnRun(unittest.TestCase):
    RUN = {"head_branch": "feature", "head_sha": SHA, "pull_requests": [{"number": 7}]}

    def test_only_a_run_on_the_branch_at_the_head_listed_for_the_pull_request_is_its_own(self):
        self.assertTrue(bot_fix.own_run(self.RUN, "feature", SHA, 7))
        self.assertFalse(bot_fix.own_run({**self.RUN, "head_branch": "main"}, "feature", SHA, 7))
        self.assertFalse(bot_fix.own_run({**self.RUN, "head_sha": "2" * 40}, "feature", SHA, 7))
        self.assertFalse(bot_fix.own_run({**self.RUN, "pull_requests": [{"number": 8}, "x"]}, "feature", SHA, 7))
        self.assertFalse(bot_fix.own_run({**self.RUN, "pull_requests": None}, "feature", SHA, 7))


class Refusal(unittest.TestCase):
    def test_a_fix_within_the_diff_and_the_cap_is_allowed(self):
        self.assertEqual(bot_fix.refusal(fixes(), {PATH}, CONFIG, 10), "")

    def test_composer_json_is_within_reach_of_the_checks_fix_alone(self):
        normalize = "diff --git a/composer.json b/composer.json\n@@ -1 +1 @@\n-a\n+b\n"

        self.assertEqual(bot_fix.refusal(fixes(normalize), set(), CONFIG, 10), "")
        self.assertEqual(bot_fix.refusal({"hygiene/markdown": bot_patch.parse(normalize)}, set(), CONFIG, 10), "why-not-in-diff")

    def test_a_fix_too_large_outside_the_diff_or_of_a_workflow_is_refused(self):
        workflow = "diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml\n@@ -1 +1 @@\n-a\n+b\n"

        self.assertEqual(bot_fix.refusal(fixes(), {PATH}, CONFIG, CONFIG["most_patch_bytes"] + 1), "why-too-large")
        self.assertEqual(bot_fix.refusal(fixes(), set(), CONFIG, 10), "why-not-in-diff")
        self.assertEqual(bot_fix.refusal(fixes(workflow), {".github/workflows/ci.yml"}, CONFIG, 10), "why-never")


class Applied(unittest.TestCase):
    def test_each_file_s_fixes_are_applied_in_gate_order(self):
        both = {
            "checks": bot_patch.parse(PATCH),
            "hygiene/markdown": bot_patch.parse(f"diff --git a/{PATH} b/{PATH}\n@@ -3,1 +3,1 @@\n-c\n+C\n"),
        }

        self.assertEqual(bot_fix.applied(both, {PATH: HEAD}), {PATH: "a\nB\nC\n"})

    def test_a_fix_that_changes_nothing_is_left_out(self):
        same = f"diff --git a/{PATH} b/{PATH}\n@@ -2,1 +2,1 @@\n-b\n+b\n"

        self.assertEqual(bot_fix.applied(fixes(same), {PATH: HEAD}), {})

    def test_a_file_with_no_head_or_a_hunk_that_does_not_match_is_refused(self):
        with self.assertRaises(bot_patch.Refused):
            bot_fix.applied(fixes(), {PATH: None})
        with self.assertRaises(bot_patch.Refused):
            bot_fix.applied(fixes(), {PATH: "x\ny\n"})


class Commit(unittest.TestCase):
    def test_one_commit_at_the_head_read_as_the_app(self):
        fake = fake_github.GitHub()
        fake.graphql_answer = {"createCommitOnBranch": {"commit": {"oid": "9" * 40, "url": f"{WEB}/commit/{'9' * 40}"}}}
        with fake_github.running(fake):
            made = bot_fix.commit("feature", SHA, {PATH: "x\n"}, "style: apply the fixes CI computed", "body")

        variables, token = fake.queries[0]
        self.assertEqual(token, "APP_TOKEN")
        self.assertEqual(variables["input"], {
            "branch": {"repositoryNameWithOwner": fake_github.REPOSITORY, "branchName": "feature"},
            "message": {"headline": "style: apply the fixes CI computed", "body": "body"},
            "expectedHeadOid": SHA,
            "fileChanges": {"additions": [{"path": PATH, "contents": base64.b64encode(b"x\n").decode()}]},
        })
        self.assertEqual(made["oid"], "9" * 40)


def zipped(files: dict[str, bytes]) -> bytes:
    data = io.BytesIO()
    with zipfile.ZipFile(data, "w") as opened:
        for name, content in files.items():
            opened.writestr(name, content)
    return data.getvalue()


class Command(unittest.TestCase):
    """`/fix` as a comment runs it."""

    def github(self, overrides: dict | None = None, patch: str = PATCH) -> fake_github.GitHub:
        pull = {
            "state": "open",
            "user": {"id": 1},
            "head": {"sha": SHA, "ref": "feature", "repo": {"full_name": fake_github.REPOSITORY}},
            "base": {"ref": "main", "repo": {"default_branch": "main"}},
        }
        run = {"id": 60, "status": "completed", "head_branch": "feature", "head_sha": SHA, "pull_requests": [{"number": 7}]}
        answers = {
            ("GET", "pulls/7"): pull,
            ("GET", "branches/feature"): {"protected": False},
            ("GET", "rules/branches/feature"): [],
            ("GET", "actions/workflows/ci.yml/runs"): {"workflow_runs": [run]},
            ("GET", "actions/runs/60/artifacts"): {"artifacts": [{"id": 61, "name": "evidence", "size_in_bytes": 10}]},
            ("GET", "pulls/7/files"): [{"filename": PATH, "status": "modified"}],
            ("GET", f"contents/src/Adapter/Pest/Worker.php?ref={SHA}"): {"encoding": "base64", "size": 6, "content": base64.b64encode(HEAD.encode()).decode()},
            ("POST", "issues/7/comments"): {},
            **(overrides or {}),
        }
        fake = fake_github.GitHub(answers)
        fake.archives[61] = zipped({"checks.fix.patch": patch.encode()} if patch else {})
        fake.graphql_answer = {"createCommitOnBranch": {"commit": {"oid": "9" * 40, "url": f"{WEB}/commit/{'9' * 40}"}}}
        return fake

    def reply(self, fake: fake_github.GitHub) -> str:
        with fake_github.running(fake, {"NUMBER": "7"}):
            bot_command.fix()
        return [body["body"] for method, path, body, _ in fake.writes() if path == "issues/7/comments"][0]

    def test_it_commits_the_fix_and_links_the_commit(self):
        fake = self.github()

        said = self.reply(fake)

        self.assertIn(f"{WEB}/commit/{'9' * 40}", said)
        variables, token = fake.queries[0]
        self.assertEqual(token, "APP_TOKEN")
        self.assertEqual(variables["input"]["fileChanges"]["additions"][0]["contents"], base64.b64encode(b"a\nB\nc\n").decode())
        self.assertEqual(variables["input"]["message"]["body"], REPLIES["fix-commit-body"].format(run=f"{WEB}/actions/runs/60", sha=SHA))

    def test_a_fork_a_closed_pull_request_and_a_guarded_branch_get_nothing(self):
        cases = {
            "fix-fork": {("GET", "pulls/7"): {"state": "open", "user": {"id": 1}, "head": {"sha": SHA, "ref": "feature", "repo": {"full_name": "someone/fork"}}, "base": {"ref": "main"}}},
            "closed": {("GET", "pulls/7"): {"state": "closed", "head": {"sha": SHA}, "base": {"ref": "main"}}},
            "fix-protected": {("GET", "branches/feature"): {"protected": True}},
        }
        for key, overrides in cases.items():
            fake = self.github(overrides)
            with self.subTest(key):
                said = self.reply(fake)
                self.assertTrue(said.startswith(REPLIES[key].split("{")[0][:30]), said)
                self.assertEqual(fake.queries, [])

    def test_a_head_branch_that_names_the_base_or_the_default_branch_gets_nothing(self):
        for ref in ("main", "release"):
            pull = {
                "state": "open",
                "head": {"sha": SHA, "ref": ref, "repo": {"full_name": fake_github.REPOSITORY}},
                "base": {"ref": "release", "repo": {"default_branch": "main"}},
            }
            fake = self.github({("GET", "pulls/7"): pull, ("GET", f"branches/{ref}"): {}, ("GET", f"rules/branches/{ref}"): []})
            with self.subTest(ref):
                self.assertEqual(self.reply(fake), REPLIES["fix-protected"])
                self.assertEqual(fake.queries, [])

    def test_a_run_that_has_not_finished_or_is_not_the_pull_request_s_own_gives_nothing(self):
        cases = {
            "fix-running": [{"workflow_runs": []}, {"workflow_runs": [{"id": 60, "status": "in_progress"}]}],
            "fix-unmatched": [
                {"workflow_runs": [{"id": 60, "status": "completed", "head_branch": "main", "head_sha": SHA, "pull_requests": [{"number": 7}]}]},
                {"workflow_runs": [{"id": 60, "status": "completed", "head_branch": "feature", "head_sha": SHA, "pull_requests": []}]},
            ],
        }
        for key, answers in cases.items():
            for answer in answers:
                fake = self.github({("GET", "actions/workflows/ci.yml/runs"): answer})
                with self.subTest(key, answer=answer):
                    self.assertEqual(self.reply(fake), REPLIES[key])
                    self.assertEqual(fake.queries, [])

    def test_no_fix_or_a_fix_that_changes_nothing_says_so(self):
        for patch in ("", f"diff --git a/{PATH} b/{PATH}\n@@ -2,1 +2,1 @@\n-b\n+b\n"):
            fake = self.github(patch=patch)
            with self.subTest(patch):
                self.assertIn("computed no fix", self.reply(fake))

    def test_a_fix_main_s_rules_refuse_says_why_and_how_to_make_it(self):
        stale = f"diff --git a/{PATH} b/{PATH}\n@@ -2,1 +2,1 @@\n-z\n+Z\n"
        elsewhere = "diff --git a/src/Elsewhere.php b/src/Elsewhere.php\n@@ -1 +1 @@\n-a\n+b\n"
        for patch, why in ((stale, "why-stale"), (elsewhere, "why-not-in-diff")):
            fake = self.github(patch=patch)
            with self.subTest(why):
                said = self.reply(fake)
                self.assertIn(REPLIES[why], said)
                self.assertIn(TABLES.gates["checks"]["fix"], said)
                self.assertEqual(fake.queries, [])

    def test_a_branch_that_moved_says_so(self):
        fake = self.github()
        fake.graphql_answer = github_api.Failed(422)

        self.assertEqual(self.reply(fake), REPLIES["fix-moved"])


if __name__ == "__main__":
    unittest.main()
