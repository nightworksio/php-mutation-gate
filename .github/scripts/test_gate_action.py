"""gate_action.py: its deciding half, and the steps that fetch and verify the PHAR, run against fakes of git,
gh and gpg so no test reaches a real release: python3 -m unittest discover .github/scripts"""
import hashlib
from datetime import datetime, timezone
import io
import json
import os
import subprocess
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

import action_script  # puts the action's script on the import path
import gate_action as action

PULL = {
    "pull_request": {
        "number": 12,
        "base": {"sha": "b" * 40},
        "head": {"repo": {"full_name": "octo/gate"}},
    },
    "repository": {"full_name": "octo/gate"},
}

FORK = {
    "pull_request": {
        "number": 13,
        "base": {"sha": "b" * 40},
        "head": {"repo": {"full_name": "someone/gate"}},
    },
    "repository": {"full_name": "octo/gate"},
}


class MergedRecords(unittest.TestCase):
    def test_takes_the_newest_artifact_of_each_head_of_this_repository_alone(self):
        def listed(identity, head, repository=7, expired=False):
            run = {"head_sha": head, "head_repository_id": repository}
            return {"id": identity, "workflow_run": run, "expired": expired}

        found = action.passed_artifacts(
            [listed(3, "a"), listed(2, "a"), listed(4, "b", expired=True), listed(5, "c", repository=8), listed(1, "d")],
            7,
        )

        self.assertEqual(found, {"a": 3, "d": 1})

    def test_names_only_what_this_repository_merged_into_its_default_branch(self):
        def pull(number, merged="2026-11-07T10:00:00Z", base="main", repository="octo/gate"):
            head = {"sha": f"h{number}", "repo": {"full_name": repository}}
            return {"number": number, "merged_at": merged, "base": {"ref": base}, "head": head}

        pulls = [pull(1), pull(2, merged=None), pull(3, base="next"), pull(4, repository="someone/gate")]

        self.assertEqual(action.merged_heads(pulls, "octo/gate", "main"), [(1, "h1")])

    def test_reads_no_head_from_a_repository_since_deleted(self):
        gone = {"number": 1, "merged_at": "now", "base": {"ref": "main"}, "head": {"sha": "h", "repo": None}}

        self.assertEqual(action.merged_heads([gone], "octo/gate", "main"), [])

    def test_reads_the_ledger_an_archive_holds_up_to_what_one_pass_can_take(self):
        def archive(name, size):
            held = io.BytesIO()
            with zipfile.ZipFile(held, "w") as opened:
                opened.writestr(name, b"x" * size)
            return held.getvalue()

        self.assertEqual(action.passed_ledger(archive(action.PASSED_FILE, 3)), b"xxx")
        self.assertIsNone(action.passed_ledger(archive(action.PASSED_FILE, action.MOST_PASSED_BYTES + 1)))
        self.assertIsNone(action.passed_ledger(archive("other", 3)))
        self.assertIsNone(action.passed_ledger(b"not an archive"))

    def test_keeps_what_it_read_where_a_run_reads_a_pull_requests_ledger(self):
        answers = {
            "repos/octo/gate": {"id": 7},
            f"repos/octo/gate/actions/artifacts?name={action.PASSED_ARTIFACT}&per_page=100": {"artifacts": [
                {"id": 9, "expired": False, "workflow_run": {"head_sha": "h1", "head_repository_id": 7}},
            ]},
            f"repos/octo/gate/commits?sha=tip&per_page={action.PROVING_RANGE}": [{"sha": "tip"}, {"sha": "old"}],
            "repos/octo/gate/commits/tip/pulls": [{
                "number": 12,
                "merged_at": "now",
                "base": {"ref": "main"},
                "head": {"sha": "h1", "repo": {"full_name": "octo/gate"}},
            }],
            "repos/octo/gate/commits/old/pulls": [],
        }
        held = io.BytesIO()
        with zipfile.ZipFile(held, "w") as opened:
            opened.writestr(action.PASSED_FILE, b"the pass")

        def ran(command, **_):
            path = command[2]
            if path.endswith("/zip"):
                return subprocess.CompletedProcess(command, 0, held.getvalue(), b"")
            if path not in answers:
                return subprocess.CompletedProcess(command, 1, "", "Not Found")
            return subprocess.CompletedProcess(command, 0, json.dumps(answers[path]), "")

        with tempfile.TemporaryDirectory() as into, mock.patch.object(action.subprocess, "run", ran), mock.patch.dict(
            os.environ,
            {
                "GITHUB_REPOSITORY": "octo/gate",
                "GITHUB_SHA": "tip",
                "GITHUB_EVENT_NAME": "push",
                "FETCHED": into,
                "DEFAULT_BRANCH": "main",
            },
        ):
            said = action._merged_records()
            with open(f"{into}/refs/pull/12/ledger.json.gz", "rb") as file:
                kept = file.read()

        self.assertEqual(said, {"records": "1"})
        self.assertEqual(kept, b"the pass")

    def test_names_the_pull_request_a_merge_group_was_built_for_and_no_other(self):
        queue = "refs/heads/gh-readonly-queue/main/pr-42-0123abcd"

        self.assertEqual(action.queued_pull_request(action.MERGE_GROUP, queue), 42)
        self.assertIsNone(action.queued_pull_request("push", queue))
        self.assertIsNone(action.queued_pull_request(action.MERGE_GROUP, "refs/heads/main"))

    def test_reads_a_merge_groups_pull_request_and_fetches_its_head(self):
        answers = {
            "repos/octo/gate": {"id": 7},
            f"repos/octo/gate/actions/artifacts?name={action.PASSED_ARTIFACT}&per_page=100": {"artifacts": [
                {"id": 9, "expired": False, "workflow_run": {"head_sha": "h42", "head_repository_id": 7}},
            ]},
            "repos/octo/gate/pulls/42": {"number": 42, "head": {"sha": "h42"}},
        }
        held = io.BytesIO()
        with zipfile.ZipFile(held, "w") as opened:
            opened.writestr(action.PASSED_FILE, b"the ledger")
        fetched = []

        def ran(command, **_):
            if command[0] == "git":
                fetched.append(command[-1])
                return subprocess.CompletedProcess(command, 0, b"", b"")
            path = command[2]
            if path.endswith("/zip"):
                return subprocess.CompletedProcess(command, 0, held.getvalue(), b"")
            if path not in answers:
                return subprocess.CompletedProcess(command, 1, "", "Not Found")
            return subprocess.CompletedProcess(command, 0, json.dumps(answers[path]), "")

        with tempfile.TemporaryDirectory() as into, mock.patch.object(action.subprocess, "run", ran), mock.patch.dict(
            os.environ,
            {
                "GITHUB_REPOSITORY": "octo/gate",
                "GITHUB_SHA": "group",
                "GITHUB_EVENT_NAME": action.MERGE_GROUP,
                "GITHUB_REF": "refs/heads/gh-readonly-queue/main/pr-42-0123abcd",
                "FETCHED": into,
                "DEFAULT_BRANCH": "main",
            },
        ):
            said = action._merged_records()
            with open(f"{into}/refs/pull/42/ledger.json.gz", "rb") as file:
                kept = file.read()

        self.assertEqual(said, {"records": "1"})
        self.assertEqual(kept, b"the ledger")
        self.assertEqual(fetched, ["h42"])

    def test_warns_and_reads_none_where_github_does_not_answer(self):
        def ran(command, **_):
            return subprocess.CompletedProcess(command, 1, "", "Bad credentials")

        with tempfile.TemporaryDirectory() as into, mock.patch.object(action.subprocess, "run", ran), mock.patch.dict(
            os.environ,
            {"GITHUB_REPOSITORY": "octo/gate", "GITHUB_SHA": "tip", "FETCHED": into, "DEFAULT_BRANCH": "main"},
        ), mock.patch("builtins.print") as printed:
            said = action._merged_records()

        self.assertEqual(said, {"records": "0"})
        self.assertIn("::warning::", printed.call_args.args[0])


class Refusal(unittest.TestCase):
    def test_refuses_every_event_that_writes_over_pull_request_content(self):
        for event in ("pull_request_target", "issue_comment", "workflow_run"):
            self.assertIn(event, action.refusal(event))

    def test_runs_on_every_other_event(self):
        for event in ("pull_request", "push", "schedule", "workflow_dispatch", "release"):
            self.assertIsNone(action.refusal(event))


class Scope(unittest.TestCase):
    def test_a_pull_request_is_its_own_scope(self):
        self.assertEqual(action.scope("pull_request", "refs/pull/12/merge", PULL), "refs/pull/12")

    def test_a_trusted_event_on_a_branch_is_that_branch(self):
        for event in ("push", "schedule", "workflow_dispatch"):
            self.assertEqual(action.scope(event, "refs/heads/main", {}), "refs/heads/main")

    def test_a_tag_and_an_untrusted_event_have_no_scope(self):
        self.assertIsNone(action.scope("push", "refs/tags/v1.0.0", {}))
        self.assertIsNone(action.scope("release", "refs/heads/main", {}))
        self.assertIsNone(action.scope("pull_request_target", "refs/heads/main", PULL))


class Mode(unittest.TestCase):
    def test_auto_is_full_on_schedules_dispatches_releases_and_tags(self):
        for event in ("schedule", "workflow_dispatch", "release"):
            self.assertEqual(action.mode_arguments("auto", "", event, "refs/heads/main", {}, "main"), ["--full"])
        self.assertEqual(action.mode_arguments("auto", "", "push", "refs/tags/v1.0.0", {}, "main"), ["--full"])

    def test_auto_on_a_pull_request_is_what_changed_since_its_base(self):
        self.assertEqual(
            action.mode_arguments("auto", "", "pull_request", "refs/pull/12/merge", PULL, "main"),
            [f"--changed-since={'b' * 40}"],
        )

    def test_auto_on_a_pull_request_runs_from_the_commit_its_merge_checkout_merges_onto_not_a_stale_base(self):
        onto = "e" * 40
        self.assertEqual(
            action.mode_arguments("auto", "", "pull_request", "refs/pull/12/merge", PULL, "main", onto),
            [f"--changed-since={onto}"],
        )
        self.assertEqual(
            action.mode_arguments("sampled", "", "pull_request", "refs/pull/12/merge", PULL, "main", onto),
            [f"--changed-since={onto}", "--sample"],
        )
        self.assertEqual(
            action.mode_arguments("changed", "v1.2.0", "pull_request", "refs/pull/12/merge", PULL, "main", onto),
            ["--changed-since=v1.2.0"],
        )

    def test_a_merge_checkout_merges_onto_its_first_parent_and_a_commit_of_one_parent_onto_nothing(self):
        merge, onto, head = "a" * 40, "e" * 40, "f" * 40
        self.assertEqual(action.merged_onto([merge, onto, head]), onto)
        self.assertEqual(action.merged_onto([merge, onto]), "")
        self.assertEqual(action.merged_onto([merge]), "")
        self.assertEqual(action.merged_onto([]), "")

    def test_reads_a_merge_checkout_s_parents_from_git(self):
        with tempfile.TemporaryDirectory() as directory:
            def git(*arguments: str) -> str:
                environment = {**os.environ, "GIT_AUTHOR_NAME": "a", "GIT_AUTHOR_EMAIL": "a@a", "GIT_COMMITTER_NAME": "a",
                               "GIT_COMMITTER_EMAIL": "a@a", "GIT_CONFIG_GLOBAL": os.devnull}
                ran = subprocess.run(["git", *arguments], cwd=directory, env=environment, capture_output=True, text=True, check=True)
                return ran.stdout.strip()

            git("init", "--quiet", "--initial-branch=main")
            git("commit", "--quiet", "--allow-empty", "--message=base")
            git("checkout", "--quiet", "-b", "change")
            git("commit", "--quiet", "--allow-empty", "--message=change")
            git("checkout", "--quiet", "main")
            git("commit", "--quiet", "--allow-empty", "--message=main moved on")
            onto = git("rev-parse", "HEAD")
            git("merge", "--quiet", "--no-ff", "--no-edit", "change")
            before = os.getcwd()
            os.chdir(directory)
            try:
                found = action.merged_onto(action._parents())
            finally:
                os.chdir(before)

        self.assertEqual(found, onto)

    def test_auto_on_a_merge_group_is_what_changed_since_the_commit_its_queue_built_it_on(self):
        ref = f"refs/heads/gh-readonly-queue/main/pr-12-{'c' * 40}"
        group = {"merge_group": {"base_sha": "c" * 40, "head_sha": "d" * 40}}
        self.assertEqual(
            action.mode_arguments("auto", "", "merge_group", ref, group, "main"), [f"--changed-since={'c' * 40}"]
        )
        self.assertEqual(
            action.mode_arguments("auto", "", "merge_group", ref, group, "main", "e" * 40),
            [f"--changed-since={'c' * 40}"],
        )
        self.assertEqual(
            action.mode_arguments("changed", "v1.2.0", "merge_group", ref, group, "main"), ["--changed-since=v1.2.0"]
        )
        self.assertEqual(
            action.mode_arguments("auto", "", "merge_group", ref, {"merge_group": {}}, "main"),
            ["--changed-since=origin/main"],
        )

    def test_a_merge_group_has_no_scope_and_saves_no_ledger(self):
        ref = f"refs/heads/gh-readonly-queue/main/pr-12-{'c' * 40}"
        self.assertIsNone(action.scope("merge_group", ref, {"merge_group": {"base_sha": "c" * 40}}))
        kept = action.ledgers("merge_group", ref, {"merge_group": {"base_sha": "c" * 40}}, "main", True)
        self.assertEqual([kept["own_dir"], kept["save"]], ["", "false"])
        self.assertEqual(kept["default_dir"], ".mutation-gate/ledger/refs/heads/main")

    def test_auto_on_a_push_to_the_default_branch_is_what_changed_since_the_last_commit_that_passed(self):
        self.assertEqual(
            action.mode_arguments("auto", "", "push", "refs/heads/main", {}, "main"), ["--changed-since=last-passed"]
        )

    def test_a_branch_other_than_the_default_runs_from_the_default_branch(self):
        self.assertEqual(
            action.mode_arguments("auto", "", "push", "refs/heads/feature", {}, "main"), ["--changed-since=origin/main"]
        )
        self.assertEqual(
            action.mode_arguments("changed", "", "workflow_dispatch", "refs/heads/feature", {}, "main"),
            ["--changed-since=origin/main"],
        )

    def test_changed_since_given_wins_over_the_event(self):
        self.assertEqual(
            action.mode_arguments("changed", "v1.2.0", "pull_request", "refs/pull/12/merge", PULL, "main"),
            ["--changed-since=v1.2.0"],
        )

    def test_full_is_full_whatever_the_event(self):
        self.assertEqual(
            action.mode_arguments("full", "main", "pull_request", "refs/pull/12/merge", PULL, "main"), ["--full"]
        )

    def test_unproven_considers_every_unit_and_takes_no_proof(self):
        self.assertEqual(action.mode_arguments("unproven", "", "pull_request", "refs/pull/12/merge", PULL, "main"), ["--unproven"])
        self.assertEqual(action.mode_arguments("unproven", "", "schedule", "refs/heads/main", {}, "main"), ["--unproven"])

    def test_sampled_considers_what_auto_does_and_samples_it(self):
        self.assertEqual(
            action.mode_arguments("sampled", "", "pull_request", "refs/pull/12/merge", PULL, "main"),
            [*action.mode_arguments("auto", "", "pull_request", "refs/pull/12/merge", PULL, "main"), "--sample"],
        )
        self.assertEqual(action.mode_arguments("sampled", "", "schedule", "refs/heads/main", {}, "main"), ["--full", "--sample"])

    def test_refuses_a_mode_it_does_not_know(self):
        with self.assertRaises(action.Refused):
            action.mode_arguments("fast", "", "push", "refs/heads/main", {}, "main")


class Ledgers(unittest.TestCase):
    def test_a_pull_request_restores_its_own_and_the_default_branch_and_saves_its_own(self):
        kept = action.ledgers("pull_request", "refs/pull/12/merge", PULL, "main", cache=True)
        self.assertEqual(kept["own_dir"], ".mutation-gate/ledger/refs/pull/12")
        self.assertEqual(kept["own_prefix"], action.cache_prefix("refs/pull/12"))
        self.assertEqual(kept["default_dir"], ".mutation-gate/ledger/refs/heads/main")
        self.assertEqual(kept["save"], "true")

    def test_a_fork_restores_only_the_default_branch_and_saves_nothing(self):
        kept = action.ledgers("pull_request", "refs/pull/13/merge", FORK, "main", cache=True)
        self.assertEqual((kept["own_dir"], kept["own_prefix"], kept["save"]), ("", "", "false"))
        self.assertEqual(kept["default_dir"], ".mutation-gate/ledger/refs/heads/main")

    def test_the_default_branch_restores_and_saves_itself_once(self):
        kept = action.ledgers("push", "refs/heads/main", {}, "main", cache=True)
        self.assertEqual(kept["own_dir"], ".mutation-gate/ledger/refs/heads/main")
        self.assertEqual((kept["default_dir"], kept["save"]), ("", "true"))

    def test_a_run_with_no_scope_saves_nothing(self):
        kept = action.ledgers("push", "refs/tags/v1.0.0", {}, "main", cache=True)
        self.assertEqual((kept["own_dir"], kept["save"]), ("", "false"))

    def test_no_cache_restores_and_saves_nothing(self):
        kept = action.ledgers("push", "refs/heads/main", {}, "main", cache=False)
        self.assertEqual(set(kept.values()), {"", "false"})

    def test_a_prefix_is_the_digest_of_the_scope(self):
        digest = hashlib.sha256(b"refs/heads/main").hexdigest()
        self.assertEqual(action.cache_prefix("refs/heads/main"), f"mutation-gate-ledger-{digest}-")
        self.assertFalse(action.cache_prefix("refs/heads/main2").startswith(action.cache_prefix("refs/heads/main")))


class Store(unittest.TestCase):
    def test_the_default_store_is_the_directory_at_its_default_path(self):
        self.assertTrue(action.keeps_default_store({}))
        self.assertTrue(action.keeps_default_store({"proofs": {"store": {"use": "directory"}}}))
        self.assertFalse(action.keeps_default_store({"proofs": {"store": {"use": "s3", "with": {"bucket": "b"}}}}))
        self.assertFalse(
            action.keeps_default_store({"proofs": {"store": {"use": "directory", "with": {"path": "ledgers"}}}})
        )

    def test_reads_the_copy_of_a_ledger_the_config_names_and_the_newest_where_it_names_none(self):
        self.assertEqual(action.copies({}), "newest")
        self.assertEqual(action.copies({"proofs": {"store": {"use": "directory"}}}), "newest")
        self.assertEqual(action.copies({"proofs": {"copies": "together"}}), "together")
        self.assertEqual(action.copies({"proofs": {"copies": "merged"}}), "merged")

    def test_has_deliver_keep_the_ledger_after_the_store_only_for_together_with_a_store_on_the_default_branch(self):
        self.assertTrue(action.keeps_after_store("together", "s3", True))
        self.assertFalse(action.keeps_after_store("together", "", True))
        self.assertFalse(action.keeps_after_store("together", "s3", False))
        self.assertFalse(action.keeps_after_store("newest", "s3", True))
        self.assertFalse(action.keeps_after_store("merged", "s3", True))


class PestPatch(unittest.TestCase):
    def test_patches_where_the_config_runs_pest_with_the_patches_on(self):
        self.assertTrue(
            action.patches_pest(
                {"runner": {"use": "pest", "memory": "1G"}, "pest": {"patch": True, "canary": "mutation-canary"}}
            )
        )

    def test_leaves_pest_alone_where_the_patches_are_off_or_pest_does_not_run(self):
        self.assertFalse(action.patches_pest({"runner": {"use": "pest"}, "pest": {"patch": False}}))
        self.assertFalse(action.patches_pest({"runner": {"use": "pest"}}))
        self.assertFalse(action.patches_pest({"runner": {"use": "infection"}, "pest": {"patch": True}}))
        self.assertFalse(action.patches_pest({}))


class InfectionPatch(unittest.TestCase):
    def test_writes_the_reports_the_config_asks_of_the_verdict_step_and_none_of_a_verdict_alone(self):
        every = [
            "--report=json:.mutation-gate/action/report.json",
            "--report=html:.mutation-gate/action/html",
            "--report=digest:.mutation-gate/action/mutation-gate-digest.txt",
        ]
        self.assertEqual(action.action_reports({}), every)
        self.assertEqual(action.action_reports({"outputs": {"actionReports": ["json"]}}), every[:1])
        self.assertEqual(action.action_reports({"outputs": {"actionReports": []}}), [])
        self.assertEqual(action.action_reports({"outputs": {"verdictOnly": True}}), [])

    def test_reads_what_a_doomed_shard_does_and_marks_where_the_config_names_nothing(self):
        self.assertEqual(action.fail_fast({"run": {"failFast": "cancel"}}), "cancel")
        self.assertEqual(action.fail_fast({"run": {"full": True}}), "mark")
        self.assertEqual(action.fail_fast({}), "mark")

    def test_patches_where_the_config_runs_infection(self):
        self.assertTrue(action.patches_infection({"runner": {"use": "infection", "memory": "1G"}}))

    def test_leaves_infection_alone_where_another_runner_runs(self):
        self.assertFalse(action.patches_infection({"runner": {"use": "pest"}, "pest": {"patch": True}}))
        self.assertFalse(action.patches_infection({"runner": {"use": "phpunit"}}))
        self.assertFalse(action.patches_infection({}))

    def test_warns_of_a_release_the_patch_does_not_patch_and_carries_on(self):
        said = "infection:patch patched nothing: it patches Infection 0.36, and vendor holds Infection 0.34.0."

        self.assertEqual(
            action.infection_patched(1, said),
            "Infection runs unpatched, with its own limit for each mutant: " + said,
        )

    def test_carries_on_quietly_where_the_patch_is_applied(self):
        self.assertIsNone(action.infection_patched(0, "infection:patch patched 2 of the 2 files"))

    def test_refuses_where_a_supported_release_cannot_be_patched(self):
        with self.assertRaisesRegex(action.Refused, "the lines it rewrites have moved"):
            action.infection_patched(2, "infection:patch patched nothing: the lines it rewrites have moved in x.")
        with self.assertRaisesRegex(action.Refused, "exited 127"):
            action.infection_patched(127, "")


class Keep(unittest.TestCase):
    def test_keys_the_kept_files_by_the_scope_s_prefix_and_their_digest_and_names_none_where_none_is_kept(self):
        kept = {"ledger.json.gz": b"ledger", "coverage.json.gz": b"map"}

        self.assertEqual("", action.kept_key("p-", {}))
        self.assertTrue(action.kept_key("p-", kept).startswith("p-"))
        self.assertEqual(action.kept_key("p-", kept), action.kept_key("p-", dict(reversed(list(kept.items())))))
        self.assertNotEqual(action.kept_key("p-", kept), action.kept_key("p-", {**kept, "coverage.json.gz": b"other"}))
        self.assertNotEqual(action.kept_key("p-", kept), action.kept_key("p-", {"ledger.json.gz": b"ledger"}))

    def test_copies_what_the_verdict_left_into_the_scope_s_directory_and_names_its_key(self):
        with tempfile.TemporaryDirectory() as root:
            source, own, output = os.path.join(root, "verdict"), os.path.join(root, "ledger", "refs", "heads", "main"), os.path.join(root, "output")
            os.makedirs(source)
            for name, body in (("ledger.json.gz", b"ledger"), ("delivery.json", b"{}")):
                with open(os.path.join(source, name), "wb") as file:
                    file.write(body)
            environment = {**os.environ, "FROM": source, "OWN": own, "PREFIX": "p-", "GITHUB_OUTPUT": output}
            subprocess.run([sys.executable, action.__file__, "keep"], env=environment, check=True)
            with open(output, encoding="utf-8") as file:
                said = file.read()

            self.assertEqual(["ledger.json.gz"], sorted(os.listdir(own)))
            self.assertEqual(f"key={action.kept_key('p-', {'ledger.json.gz': b'ledger'})}\n", said)


class WorkflowCommand(unittest.TestCase):
    def test_escapes_what_a_workflow_command_reads_as_its_end(self):
        self.assertEqual(action.command_text("50% done\r\nnext"), "50%25 done%0D%0Anext")


class Outputs(unittest.TestCase):
    def test_reads_each_score_by_path(self):
        report = {
            "trees": [{"path": "src", "score": 97.5}, {"path": "lib"}],
            "newCode": [{"package": ".", "score": 100}],
        }
        self.assertEqual(action.scores(report), {"trees": {"src": 97.5, "lib": None}, "newCode": {".": 100}})

    def test_names_each_report_with_its_path(self):
        paths = action.report_paths("sarif:build/m.sarif\n\n junit:build/j.xml \n", {"json": "r.json"})
        self.assertEqual(paths, {"sarif": "build/m.sarif", "junit": "build/j.xml", "json": "r.json"})
        self.assertEqual(action.report_options("sarif:build/m.sarif"), ["--report=sarif:build/m.sarif"])

    def test_lists_each_report_file_once_a_line(self):
        self.assertEqual(action.report_files({"sarif": "m.sarif", "json": "r.json", "html": "m.sarif"}), "m.sarif\nr.json")

    def test_refuses_a_report_line_without_a_path(self):
        with self.assertRaises(action.Refused):
            action.report_paths("sarif", {})

    def test_says_the_verdict_of_each_exit_code(self):
        self.assertEqual([action.verdict(code) for code in (0, 1, 2, 255)], ["passed", "failed", "cannot-judge", "cannot-judge"])

    def test_writes_a_value_over_several_lines_between_delimiters(self):
        self.assertEqual(action.output_lines({"a": "1"}), "a=1\n")
        written = action.output_lines({"plan": "{\n}"})
        self.assertTrue(written.startswith("plan<<EOF_"))
        self.assertIn("\n{\n}\n", written)
