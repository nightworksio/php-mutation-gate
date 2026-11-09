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


class Installed(unittest.TestCase):
    def test_the_package_itself_runs_its_own_bin(self):
        self.assertEqual(action.binary({"name": "nightworksio/mutation-gate"}), "bin/mutation-gate")
        self.assertEqual(action.binary({"name": "acme/shop"}), "vendor/bin/mutation-gate")

    def test_a_line_is_the_major_or_while_it_is_0_the_major_and_minor(self):
        self.assertEqual(action.line_of("v0.1.0"), "0.1")
        self.assertEqual(action.line_of("0.12.3"), "0.12")
        self.assertEqual(action.line_of("v1.4.0"), "1")
        self.assertEqual(action.line_of("2.0.0"), "2")

    def test_accepts_the_same_line_and_a_development_version(self):
        for installed in ("0.1.0", "v0.1.7", "dev-main", "0.1.x-dev"):
            self.assertIsNone(action.version_refusal(installed, "0.1"))
        self.assertIsNone(action.version_refusal("1.4.0", "1"))

    def test_reads_the_gates_version_from_what_composer_installed(self):
        installed = {"packages": [{"name": "acme/other", "version": "2.0.0"}, {"name": action.GATE, "version": "v0.1.3"}]}
        self.assertEqual(action.installed_version(installed), "v0.1.3")
        self.assertEqual(action.installed_version({"packages": [{"name": "acme/other", "version": "2.0.0"}]}), "")
        self.assertEqual(action.installed_version({}), "")
        self.assertEqual(action.installed_version({"packages": {"name": action.GATE}}), "")
        self.assertEqual(action.installed_version({"packages": [{"name": action.GATE, "version": 1}]}), "")

    def test_refuses_a_project_that_does_not_install_the_gate(self):
        self.assertIn("does not install", action.version_refusal("", "0.1"))

    def test_refuses_another_line(self):
        self.assertIn("^0.1", action.version_refusal("0.2.0", "0.1"))
        self.assertIn("1.0.0", action.version_refusal("1.0.0", "0.1"))
        self.assertIn("2.0.0", action.version_refusal("2.0.0", "1"))

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


class Stale(unittest.TestCase):
    def test_reads_each_shard_job_s_latest_attempt_by_its_number_under_the_calling_job_s_name(self):
        jobs = [
            {"name": "mutation / shard 3 (src, part 3 of 12)", "run_attempt": 2},
            {"name": "mutation / shard 3 (src, part 3 of 12)", "run_attempt": 1},
            {"name": "shard 12 (plugins/default/src)", "run_attempt": 1},
            {"name": "mutation / verdict", "run_attempt": 2},
            {"name": "mutation / reshard 4 (src)", "run_attempt": 2},
            {"name": "mutation / shard 5 (src)", "run_attempt": "2"},
            "mutation / shard 6 (src)",
        ]

        self.assertEqual(action.shard_attempts(jobs), {"3": 2, "12": 1})

    def test_counts_no_job_a_re_run_kept_from_an_earlier_attempt_which_started_before_it_was_created(self):
        jobs = [
            {
                "name": "mutation / shard 1 (src, part 1 of 20)",
                "run_attempt": 2,
                "created_at": "2026-11-03T05:15:02Z",
                "started_at": "2026-11-03T04:17:08Z",
            },
            {
                "name": "mutation / shard 4 (src, part 4 of 20)",
                "run_attempt": 2,
                "created_at": "2026-11-03T05:15:03Z",
                "started_at": "2026-11-03T05:15:07Z",
            },
        ]

        self.assertEqual(action.shard_attempts(jobs), {"4": 2})
        self.assertEqual(action.stale({"1": 1, "4": 2}, action.shard_attempts(jobs)), [])

    def test_names_each_shard_whose_result_an_attempt_before_its_job_s_latest_run_wrote(self):
        written = {"10": 1, "2": 1, "3": 2, "4": 0, "7": 1}
        latest = {"10": 2, "2": 1, "3": 2, "4": 1}

        self.assertEqual(action.stale(written, latest), ["4", "10"])

    def test_says_which_attempt_ran_the_shard_again_and_which_wrote_the_result_left(self):
        why = action.stale_text("4", 1, 2)

        self.assertIn("Shard 4 ran again in attempt 2 and wrote no result", why)
        self.assertIn("the result attempt 1 wrote is not judged", why)


def shard_job(number: int, conclusion: str, completed: str, **more) -> dict:
    """A shard job of attempt 1 as GitHub lists it, with these fields beside."""
    return {
        "id": 100 + number,
        "name": f"mutation / shard {number} (src, part {number} of 12)",
        "run_attempt": 1,
        "created_at": "2026-11-08T18:39:30Z",
        "started_at": "2026-11-08T18:39:36Z",
        "completed_at": completed,
        "conclusion": conclusion,
        "html_url": f"https://github.com/octo/gate/actions/runs/1/job/{100 + number}",
        "steps": [{"name": "Set up job", "conclusion": "success"}],
    } | more


class Ended(unittest.TestCase):
    def test_names_a_runner_shut_down_and_a_shard_the_matrix_cancelled_once_another_failed(self):
        shutdown = "##[error]The runner has received a shutdown signal. This can happen when the runner service is stopped"
        lost = [{"name": "Run ./.mutation-gate/workflow", "conclusion": None}]
        jobs = [
            shard_job(1, "cancelled", "2026-11-08T19:18:47Z"),
            shard_job(3, "success", "2026-11-08T20:02:34Z"),
            shard_job(7, "cancelled", "2026-11-08T20:19:52Z"),
            shard_job(10, "failure", "2026-11-08T20:19:38Z", steps=lost),
            {"name": "mutation / verdict", "run_attempt": 1, "conclusion": "failure"},
        ]

        ended = action.endings(jobs, {101: shutdown, 107: "##[error]The operation was canceled."})

        self.assertEqual(
            ended,
            {
                "1": {"how": "shutdown", "job": "https://github.com/octo/gate/actions/runs/1/job/101"},
                "7": {"how": "cancelled", "job": "https://github.com/octo/gate/actions/runs/1/job/107", "after": 10},
                "10": {"how": "lost", "job": "https://github.com/octo/gate/actions/runs/1/job/110"},
            },
        )

    def test_a_cancel_before_any_shard_failed_names_none_and_a_failure_whose_steps_all_ended_failed(self):
        jobs = [
            shard_job(2, "cancelled", "2026-11-08T19:00:00Z"),
            shard_job(4, "failure", "2026-11-08T19:30:00Z"),
            shard_job(5, "failure", "2026-11-08T19:40:00Z"),
        ]

        ended = action.endings(jobs, {})

        self.assertEqual(ended["2"], {"how": "cancelled", "job": jobs[0]["html_url"]})
        self.assertEqual(ended["4"]["how"], "failed")

    def test_a_job_past_its_time_limit_timed_out(self):
        said = "##[error]The job has exceeded the maximum execution time of 6h0m0s"

        self.assertEqual(action.ending(shard_job(1, "cancelled", ""), said), "timed-out")
        self.assertEqual(action.ending(shard_job(1, "timed_out", ""), ""), "timed-out")

    def test_reads_only_each_shard_s_latest_run_and_not_a_run_a_re_run_kept(self):
        earlier = shard_job(6, "cancelled", "2026-11-08T19:00:00Z")
        again = shard_job(6, "success", "2026-11-08T21:00:00Z", run_attempt=2, id=206)
        carried = shard_job(8, "cancelled", "2026-11-08T19:10:00Z", run_attempt=2, created_at="2026-11-08T21:00:00Z")

        self.assertEqual(action.endings([earlier, again, carried], {}), {})

    def test_writes_how_each_shard_without_a_result_ended_beside_where_its_result_would_be(self):
        with tempfile.TemporaryDirectory() as directory:
            results, errors = os.path.join(directory, "results"), os.path.join(directory, "errors")
            os.makedirs(results)
            os.makedirs(errors)
            jobs = os.path.join(directory, "jobs.json")
            with open(jobs, "w", encoding="utf-8") as file:
                json.dump([shard_job(1, "cancelled", "a"), shard_job(2, "failure", "b")], file)
            with open(os.path.join(errors, "101.txt"), "w", encoding="utf-8") as file:
                file.write("##[error]The runner has received a shutdown signal.")
            with open(os.path.join(results, "2.json"), "w", encoding="utf-8") as file:
                file.write("{}")

            subprocess.run(
                [sys.executable, action_script.PATH, "ended"],
                env={**os.environ, "JOBS": jobs, "RESULTS": results, "ERRORS": errors},
                check=True,
            )

            with open(os.path.join(results, "1.ended.json"), encoding="utf-8") as file:
                self.assertEqual(json.load(file)["how"], "shutdown")
            self.assertFalse(os.path.exists(os.path.join(results, "2.ended.json")))


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


class RunTimesStep(unittest.TestCase):
    def test_writes_what_the_listed_jobs_took_and_nothing_where_it_lists_none(self):
        with tempfile.TemporaryDirectory() as directory:
            jobs = os.path.join(directory, "jobs.json")
            times = os.path.join(directory, "times.json")
            with open(jobs, "w", encoding="utf-8") as file:
                json.dump([{"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:00:30Z"}], file)
            environment = {**os.environ, "JOBS": jobs, "RUN_TIMES": times, "GITHUB_OUTPUT": os.path.join(directory, "outputs")}

            ran = subprocess.run([sys.executable, action_script.PATH, "run-times"], env=environment, capture_output=True, text=True, check=False)
            written = os.path.isfile(times)
            os.remove(times)
            none = subprocess.run([sys.executable, action_script.PATH, "run-times"], env={**environment, "JOBS": os.path.join(directory, "gone.json")}, capture_output=True, text=True, check=False)

            self.assertEqual((0, True), (ran.returncode, written), ran.stderr)
            self.assertEqual((0, False), (none.returncode, os.path.isfile(times)), none.stderr)


class RunTimes(unittest.TestCase):
    NOW = datetime(2026, 11, 7, 12, 0, 0, tzinfo=timezone.utc)

    def test_sums_each_job_and_spans_the_run_counting_a_running_job_up_to_now(self):
        jobs = [
            {"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:10:00Z"},
            {"started_at": "2026-11-07T11:05:00Z", "completed_at": "2026-11-07T11:20:00Z"},
            {"started_at": "2026-11-07T11:59:00Z", "completed_at": None},
        ]

        self.assertEqual(
            {"wallSeconds": 3600.0, "runnerSeconds": 600.0 + 900.0 + 60.0},
            action.run_times(jobs, self.NOW),
        )

    def test_says_when_the_plan_s_job_began_and_what_it_took(self):
        jobs = [
            {"name": "mutation / plan", "started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:02:00Z"},
            {"name": "mutation / explain the plan", "started_at": "2026-11-07T11:01:00Z", "completed_at": "2026-11-07T11:02:00Z"},
        ]

        self.assertEqual(
            {"at": "2026-11-07T11:00:00Z", "seconds": 120.0},
            action.run_times(jobs, self.NOW)["plan"],
        )

    def test_leaves_out_a_job_that_never_started_and_says_nothing_of_a_run_with_none(self):
        started = {"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:00:30Z"}

        self.assertEqual(
            {"wallSeconds": 30.0, "runnerSeconds": 30.0},
            action.run_times([started, {"started_at": None}, {"started_at": "not a time"}], self.NOW),
        )
        self.assertIsNone(action.run_times([{"started_at": None}], self.NOW))
        self.assertIsNone(action.run_times([], self.NOW))


if __name__ == "__main__":
    unittest.main()


# What `git ls-remote --tags` lists: a release tag, annotated, with the commit
# it points at; the line's tag at the same commit; and a release at another.
LISTED = (
    f"{'1' * 40}\trefs/tags/v0.1.0\n"
    f"{'a' * 40}\trefs/tags/v0.1.1\n"
    f"{'2' * 40}\trefs/tags/v0.1.1^{{}}\n"
    f"{'2' * 40}\trefs/tags/v0.1\n"
    f"{'3' * 40}\trefs/heads/main\n"
)

# gpg's status lines for a good signature by the release key, and by another.
GOOD = (
    f"[GNUPG:] GOODSIG B757A4B1136116D2 NightWorksIO Releases\n"
    f"[GNUPG:] VALIDSIG {action.RELEASE_FINGERPRINT} 2026-10-31 1793106457 0 4 0 22 10 00 {action.RELEASE_FINGERPRINT}\n"
)
OTHER = GOOD.replace(action.RELEASE_FINGERPRINT, "F" * 40)


class Install(unittest.TestCase):
    def test_auto_runs_the_project_s_copy_where_it_requires_the_gate_and_the_phar_where_it_does_not(self):
        for manifest in ({"require": {action.GATE: "^0.1"}}, {"require-dev": {action.GATE: "^0.1"}}, {"name": action.GATE}):
            self.assertEqual("composer", action.install("auto", manifest))
        self.assertEqual("phar", action.install("auto", {"require-dev": {"phpunit/phpunit": "^13"}}))

    def test_composer_and_phar_are_as_asked(self):
        self.assertEqual("composer", action.install("composer", {}))
        self.assertEqual("phar", action.install("phar", {"require": {action.GATE: "^0.1"}}))

    def test_refuses_a_way_it_does_not_know(self):
        with self.assertRaises(action.Refused) as refused:
            action.install("docker", {})
        self.assertIn("auto, composer or phar, not docker", str(refused.exception))


class ReleaseTag(unittest.TestCase):
    def test_the_ref_is_github_s_or_the_last_part_of_the_path_it_fetched_the_action_into(self):
        self.assertEqual("v0.1", action.action_ref("v0.1", "/runner/_actions/nightworksio/php-mutation-gate/main"))
        self.assertEqual("v0.1.1", action.action_ref("", "/runner/_actions/nightworksio/php-mutation-gate/v0.1.1/"))

    def test_reads_each_tag_s_commit_an_annotated_tag_by_the_commit_it_points_at(self):
        self.assertEqual(
            {"v0.1.0": "1" * 40, "v0.1.1": "2" * 40, "v0.1": "2" * 40},
            action.remote_tags(LISTED),
        )

    def test_names_the_release_a_release_tag_a_line_s_tag_or_a_commit_names(self):
        tags = action.remote_tags(LISTED)
        self.assertEqual("v0.1.0", action.release_tag("v0.1.0", tags))
        self.assertEqual("v0.1.1", action.release_tag("v0.1", tags))
        self.assertEqual("v0.1.1", action.release_tag("2" * 40, tags))

    def test_names_the_highest_release_at_one_commit(self):
        self.assertEqual("v0.1.10", action.release_tag("4" * 40, {"v0.1.9": "4" * 40, "v0.1.10": "4" * 40}))

    def test_refuses_a_ref_that_is_no_release_s_tag_or_commit(self):
        tags = action.remote_tags(LISTED)
        for ref in ("main", "3" * 40, "v0.2.0", "v0.2", ""):
            with self.subTest(ref=ref), self.assertRaises(action.Refused) as refused:
                action.release_tag(ref, tags)
            self.assertIn("is no release's tag or commit", str(refused.exception))
            self.assertIn(f"or require {action.GATE} with Composer, and the action runs that copy.", str(refused.exception))


class Signature(unittest.TestCase):
    def test_accepts_a_good_signature_by_the_release_key(self):
        self.assertTrue(action.signed_by(GOOD, action.RELEASE_FINGERPRINT))

    def test_refuses_a_signature_by_another_key_a_bad_one_and_one_by_an_expired_key(self):
        self.assertFalse(action.signed_by(OTHER, action.RELEASE_FINGERPRINT))
        self.assertFalse(action.signed_by(GOOD.replace("GOODSIG", "BADSIG"), action.RELEASE_FINGERPRINT))
        self.assertFalse(action.signed_by(GOOD.replace("GOODSIG", "EXPKEYSIG"), action.RELEASE_FINGERPRINT))
        self.assertFalse(action.signed_by(GOOD.splitlines()[0], action.RELEASE_FINGERPRINT))


class PharRunner(unittest.TestCase):
    def test_the_phar_refuses_pest_which_only_the_composer_package_runs(self):
        self.assertIn("composer require --dev", action.phar_refusal({"runner": {"use": "pest"}}, "phar"))

    def test_runs_every_other_runner_and_pest_from_the_project_s_copy(self):
        self.assertIsNone(action.phar_refusal({"runner": {"use": "infection"}}, "phar"))
        self.assertIsNone(action.phar_refusal({"runner": {"use": "pest"}}, "composer"))


# Fakes of the commands the PHAR steps run, each doing what its environment
# says: git lists LISTED, gh downloads a release and verifies its attestation,
# gpg imports the key and prints the status lines in STATUS.
FAKE_GIT = """#!/bin/sh
[ -n "${GIT_FAILS}" ] && { echo "could not resolve host" >&2; exit 128; }
printf '%s' "${LISTED}"
"""

FAKE_GH = """#!/bin/sh
case "$*" in
  "release download"*)
    [ -n "${DOWNLOAD_FAILS}" ] && { echo "release not found" >&2; exit 1; }
    for argument in "$@"; do [ "${previous}" = "--dir" ] && dir="${argument}"; previous="${argument}"; done
    previous=""
    for argument in "$@"; do [ "${previous}" = "--pattern" ] && echo "${argument}" > "${dir}/${argument}"; previous="${argument}"; done ;;
  "release view"*)
    [ -n "${NO_RELEASE}" ] && { echo "release not found" >&2; exit 1; }
    echo v0.1.1 ;;
  "attestation verify"*)
    echo "$*" > "${ASKED}"
    [ -n "${UNATTESTED}" ] && { echo "no attestations found" >&2; exit 1; }
    exit 0 ;;
esac
"""

FAKE_GPG = """#!/bin/sh
case "$*" in
  *--verify*) cat "${STATUS}" ;;
esac
"""


def faked(directory: str) -> str:
    """A directory of the fake commands, for PATH."""
    found = os.path.join(directory, "bin")
    os.makedirs(found)
    for name, script in (("git", FAKE_GIT), ("gh", FAKE_GH), ("gpg", FAKE_GPG)):
        with open(os.path.join(found, name), "w", encoding="utf-8") as file:
            file.write(script)
        os.chmod(os.path.join(found, name), 0o755)
    return found


def step(subcommand: str, directory: str, manifest: dict, stdin: str = "", **env: str) -> tuple[int, dict, str]:
    """A subcommand run in a project of this composer.json: its exit code, its outputs and what it said."""
    with open(os.path.join(directory, "composer.json"), "w", encoding="utf-8") as file:
        json.dump(manifest, file)
    outputs = os.path.join(directory, "outputs")
    status = os.path.join(directory, "status")
    with open(status, "w", encoding="utf-8") as file:
        file.write(env.pop("status", GOOD))
    ran = subprocess.run(
        [sys.executable, action_script.PATH, subcommand],
        cwd=directory,
        input=stdin,
        env={
            **os.environ,
            "PATH": f"{faked(directory)}{os.pathsep}{os.environ['PATH']}",
            "GITHUB_OUTPUT": outputs,
            "GITHUB_EVENT_NAME": "pull_request",
            "RUNNER_TEMP": directory,
            "LISTED": LISTED,
            "STATUS": status,
            "ASKED": os.path.join(directory, "asked"),
            **env,
        },
        capture_output=True,
        text=True,
        check=False,
        timeout=60,
    )
    written = {}
    if os.path.isfile(outputs):
        with open(outputs, encoding="utf-8") as file:
            written = dict(line.split("=", 1) for line in file.read().splitlines() if "=" in line)
    return ran.returncode, written, ran.stderr


NOT_REQUIRED = {"require-dev": {"phpunit/phpunit": "^13"}}


class InstallStep(unittest.TestCase):
    def test_runs_the_project_s_copy_where_it_requires_the_gate(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, _ = step("install", directory, {"require-dev": {action.GATE: "^0.1"}}, INSTALL="auto")

            self.assertEqual((0, {"install": "composer"}), (code, outputs))

    def test_names_the_release_of_the_ref_the_action_is_pinned_to_where_it_does_not(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("install", directory, NOT_REQUIRED, INSTALL="auto", GITHUB_ACTION_REF="v0.1")

            self.assertEqual((0, {"install": "phar", "tag": "v0.1.1"}), (code, outputs), said)

    def test_refuses_where_the_release_tags_cannot_be_listed(self):
        with tempfile.TemporaryDirectory() as directory:
            code, _, said = step("install", directory, NOT_REQUIRED, INSTALL="phar", GITHUB_ACTION_REF="v0.1", GIT_FAILS="1")

            self.assertEqual(2, code)
            self.assertIn(f"::error::The tags of {action.RELEASES} could not be listed: could not resolve host", said)


class PharStep(unittest.TestCase):
    def test_names_the_downloaded_phar_once_its_signature_and_attestation_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1")

            self.assertEqual(0, code, said)
            self.assertTrue(outputs["gate"].endswith(f"/{action.PHAR}"))
            self.assertTrue(os.access(outputs["gate"], os.X_OK))
            with open(os.path.join(directory, "asked"), encoding="utf-8") as file:
                asked = file.read().split()
            self.assertEqual(["--repo", action.RELEASES, "--signer-workflow", action.RELEASE_WORKFLOW], asked[3:7])
            self.assertEqual(["--source-ref", "refs/tags/v0.1.1"], asked[7:9])

    def test_runs_nothing_whose_signature_is_not_the_release_key_s(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", status=OTHER)

            self.assertEqual((2, {}), (code, outputs))
            self.assertIn(f"The PHAR of v0.1.1 is not signed by the release key {action.RELEASE_FINGERPRINT}", said)
            self.assertFalse(os.path.exists(os.path.join(directory, "asked")))

    def test_runs_nothing_that_carries_no_attestation_of_the_release_workflow(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", UNATTESTED="1")

            self.assertEqual((2, {}), (code, outputs))
            self.assertIn(f"carries no attestation of {action.RELEASE_WORKFLOW}, so it is not run: no attestations found", said)

    def test_refuses_where_the_release_cannot_be_downloaded(self):
        with tempfile.TemporaryDirectory() as directory:
            code, _, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", DOWNLOAD_FAILS="1")

            self.assertEqual(2, code)
            self.assertIn("The PHAR of v0.1.1 could not be downloaded: release not found", said)


class PharRun(unittest.TestCase):
    def test_runs_the_phar_where_the_project_installs_no_gate(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("resolve", directory, NOT_REQUIRED, INSTALLED_BY="phar", GATE_PHAR="/tmp/gate.phar")

            self.assertEqual(0, code, said)
            self.assertEqual("/tmp/gate.phar", outputs["gate"])

    def test_the_project_s_copy_is_still_held_to_the_action_s_line(self):
        with tempfile.TemporaryDirectory() as directory:
            code, _, said = step("resolve", directory, NOT_REQUIRED, INSTALLED_BY="composer")

            self.assertEqual(2, code)
            self.assertIn(f"The project does not install {action.GATE}", said)

    def test_refuses_pest_under_the_phar_before_anything_runs(self):
        with tempfile.TemporaryDirectory() as directory:
            pest = json.dumps({"runner": {"use": "pest"}})
            code, _, said = step("config", directory, NOT_REQUIRED, stdin=pest, INSTALLED_BY="phar")

            self.assertEqual(2, code)
            self.assertIn("so the PHAR cannot run it", said)


class JudgeCandidates(unittest.TestCase):
    def test_release_is_the_newest_release_s_judge_and_builds_nothing(self):
        self.assertEqual(([], True), action.judge_candidates("release", "pull_request", PULL, "octo/gate", "main"))

    def test_a_commit_or_tag_builds_that_alone(self):
        self.assertEqual(([["v0.2.0"]], False), action.judge_candidates("v0.2.0", "push", {}, "octo/gate", "main"))

    def test_merge_base_builds_where_a_change_branched_then_its_base_then_takes_the_newest_release(self):
        pull = {"pull_request": {"base": {"sha": "b" * 40}, "head": {"sha": "h" * 40}}}
        group = {"merge_group": {"base_sha": "g" * 40}}

        self.assertEqual(
            ([["b" * 40, "h" * 40], ["b" * 40]], True),
            action.judge_candidates("merge-base", "pull_request", pull, action.RELEASES, "main"),
        )
        self.assertEqual(
            ([["g" * 40, "HEAD"], ["g" * 40]], True),
            action.judge_candidates("merge-base", "merge_group", group, action.RELEASES, "main"),
        )
        self.assertEqual(
            ([["origin/main", "HEAD"], ["origin/main"]], True),
            action.judge_candidates("merge-base", "push", {}, action.RELEASES, "main"),
        )

    def test_its_own_history_is_the_gate_s_own_repository_s_alone(self):
        for judge in action.JUDGE_OWN_HISTORY:
            with self.subTest(judge), self.assertRaises(action.Refused) as refused:
                action.judge_candidates(judge, "pull_request", PULL, "octo/gate", "main")

            self.assertIn(f"judge: {judge} builds the gate from its own history", str(refused.exception))
            self.assertIn(f"only {action.RELEASES} names it", str(refused.exception))


def judged(directory: str, payload: dict, **env: str) -> tuple[int, dict, str]:
    """The judge subcommand run in a git repository, with a fake gh: its exit code, its outputs and what it said."""
    event = os.path.join(directory, "event.json")
    with open(event, "w", encoding="utf-8") as file:
        json.dump(payload, file)
    outputs = os.path.join(directory, "outputs")
    fakes = os.path.join(directory, "fakes")
    os.makedirs(fakes, exist_ok=True)
    with open(os.path.join(fakes, "gh"), "w", encoding="utf-8") as file:
        file.write(FAKE_GH)
    os.chmod(os.path.join(fakes, "gh"), 0o755)
    ran = subprocess.run(
        [sys.executable, action_script.PATH, "judge"],
        cwd=directory,
        env={
            **os.environ,
            "PATH": f"{fakes}{os.pathsep}{os.environ['PATH']}",
            "GITHUB_OUTPUT": outputs,
            "GITHUB_EVENT_NAME": "pull_request",
            "GITHUB_EVENT_PATH": event,
            "GITHUB_REPOSITORY": action.RELEASES,
            "DEFAULT_BRANCH": "main",
            **env,
        },
        capture_output=True,
        text=True,
        check=False,
        timeout=60,
    )
    written = {}
    if os.path.isfile(outputs):
        with open(outputs, encoding="utf-8") as file:
            written = dict(line.split("=", 1) for line in file.read().splitlines() if "=" in line)
    return ran.returncode, written, ran.stderr


class JudgeStep(unittest.TestCase):
    @staticmethod
    def git(directory: str, *arguments: str) -> str:
        environment = {**os.environ, "GIT_AUTHOR_NAME": "a", "GIT_AUTHOR_EMAIL": "a@a", "GIT_COMMITTER_NAME": "a",
                       "GIT_COMMITTER_EMAIL": "a@a", "GIT_CONFIG_GLOBAL": os.devnull}
        ran = subprocess.run(["git", *arguments], cwd=directory, env=environment, capture_output=True, text=True, check=True)
        return ran.stdout.strip()

    def touching(self, directory: str, path: str) -> str:
        """The change branch's tip after a commit that writes a file at a path, or under it where it names a directory."""
        self.git(directory, "checkout", "--quiet", "change")
        file = path if os.path.splitext(path)[1] else os.path.join(path, "touched")
        os.makedirs(os.path.dirname(os.path.join(directory, file)), exist_ok=True)
        with open(os.path.join(directory, file), "w", encoding="utf-8") as written:
            written.write("touched")
        self.git(directory, "add", file)
        self.git(directory, "commit", "--quiet", "--message=touch")
        return self.git(directory, "rev-parse", "HEAD")

    def history(self, directory: str, build_at_fork: bool) -> tuple[str, str, str]:
        """A base branch and a change branched from it: the fork point, the base's tip and the change's tip."""
        def git(*arguments: str) -> str:
            return self.git(directory, *arguments)

        git("init", "--quiet", "--initial-branch=main")
        if build_at_fork:
            os.makedirs(os.path.join(directory, "scripts/judge"))
            with open(os.path.join(directory, action.JUDGE_BUILD), "w", encoding="utf-8") as file:
                file.write("")
            git("add", "scripts")
        git("commit", "--quiet", "--allow-empty", "--message=fork")
        fork = git("rev-parse", "HEAD")
        git("checkout", "--quiet", "-b", "change")
        git("commit", "--quiet", "--allow-empty", "--message=change")
        change = git("rev-parse", "HEAD")
        git("checkout", "--quiet", "main")
        os.makedirs(os.path.join(directory, "scripts/judge"), exist_ok=True)
        with open(os.path.join(directory, action.JUDGE_BUILD), "w", encoding="utf-8") as file:
            file.write("# moved on")
        git("add", "scripts")
        git("commit", "--quiet", "--message=main moved on")
        return fork, git("rev-parse", "HEAD"), change

    def test_builds_where_the_change_branched_where_that_commit_builds_a_judge(self):
        with tempfile.TemporaryDirectory() as directory:
            fork, base, change = self.history(directory, build_at_fork=True)
            pull = {"pull_request": {"base": {"sha": base}, "head": {"sha": change}}}

            code, outputs, said = judged(directory, pull, JUDGE="merge-base")

            self.assertEqual((0, {"commit": fork}), (code, outputs), said)

    def test_builds_from_the_base_where_the_fork_point_builds_none(self):
        with tempfile.TemporaryDirectory() as directory:
            _, base, change = self.history(directory, build_at_fork=False)
            pull = {"pull_request": {"base": {"sha": base}, "head": {"sha": change}}}

            code, outputs, said = judged(directory, pull, JUDGE="merge-base")

            self.assertEqual((0, {"commit": base}), (code, outputs), said)

    def test_merge_base_builds_where_a_change_to_what_calls_a_judge_branched(self):
        with tempfile.TemporaryDirectory() as directory:
            fork, base, _ = self.history(directory, build_at_fork=True)
            change = self.touching(directory, "action.yml")
            pull = {"pull_request": {"base": {"sha": base}, "head": {"sha": change}}}

            code, outputs, said = judged(directory, pull, JUDGE="merge-base")

            self.assertEqual((0, {"commit": fork}), (code, outputs), said)

    def test_what_the_base_moved_on_to_is_not_the_change(self):
        with tempfile.TemporaryDirectory() as directory:
            fork, base, change = self.history(directory, build_at_fork=True)
            pull = {"pull_request": {"base": {"sha": base}, "head": {"sha": change}}}

            code, outputs, said = judged(directory, pull, JUDGE="merge-base")

            self.assertEqual((0, {"commit": fork}), (code, outputs), said)

    def test_merge_base_takes_the_newest_release_where_neither_commit_builds_a_judge(self):
        with tempfile.TemporaryDirectory() as directory:
            fork, _, change = self.history(directory, build_at_fork=False)
            pull = {"pull_request": {"base": {"sha": fork}, "head": {"sha": change}}}

            code, outputs, said = judged(directory, pull, JUDGE="merge-base")

        self.assertEqual((0, {"release": "true", "tag": "v0.1.1"}), (code, outputs), said)

    def test_takes_the_newest_release_where_no_commit_builds_one_and_refuses_a_named_commit_that_cannot(self):
        with tempfile.TemporaryDirectory() as directory:
            fork, _, _ = self.history(directory, build_at_fork=False)

            released = judged(directory, {}, JUDGE="release")
            named = judged(directory, {}, JUDGE=fork)

        self.assertEqual((0, {"release": "true", "tag": "v0.1.1"}), released[:2], released[2])
        self.assertEqual(2, named[0])
        self.assertIn(f"{fork} builds no judge: it holds no {action.JUDGE_BUILD}", named[2])

    def test_refuses_where_no_release_can_be_found(self):
        with tempfile.TemporaryDirectory() as directory:
            self.history(directory, build_at_fork=False)

            code, _, said = judged(directory, {}, JUDGE="release", NO_RELEASE="1")

        self.assertEqual(2, code)
        self.assertIn(f"No release of {action.RELEASES} could be found to judge with: release not found", said)


class JudgeRun(unittest.TestCase):
    def test_runs_the_judge_s_phar_whatever_the_project_installs_and_its_pest(self):
        with tempfile.TemporaryDirectory() as directory, tempfile.TemporaryDirectory() as other:
            code, outputs, said = step("resolve", directory, NOT_REQUIRED, INSTALLED_BY="judge", GATE_PHAR="/tmp/judge.phar")
            pest = json.dumps({"runner": {"use": "pest"}})
            configured, _, refused = step("config", other, NOT_REQUIRED, stdin=pest, INSTALLED_BY="judge")

        self.assertEqual((0, "/tmp/judge.phar"), (code, outputs["gate"]), said)
        self.assertEqual(0, configured, refused)

    def test_downloads_the_release_s_judge_where_asked_for_it(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", ASSET=action.JUDGE_PHAR)

            self.assertEqual(0, code, said)
            self.assertTrue(outputs["gate"].endswith(f"/{action.JUDGE_PHAR}"))

