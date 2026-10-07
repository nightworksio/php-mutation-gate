"""The deciding half of gate_action.py: python3 -m unittest discover .github/scripts"""
import hashlib
import unittest

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


QUEUE = "refs/heads/gh-readonly-queue/main/pr-12-" + "c" * 40


class Mode(unittest.TestCase):
    def test_each_event_is_change_scoped_by_default_and_none_runs_in_full_unasked(self):
        expected = {
            ("pull_request", "refs/pull/12/merge"): "--changed-since=last-run",
            ("push", "refs/heads/main"): "--changed-since=last-passed",
            ("push", "refs/heads/feature"): "--changed-since=refs/remotes/origin/main",
            ("push", "refs/tags/v1.0.0"): "--changed-since=last-passed",
            ("schedule", "refs/heads/main"): "--changed-since=last-passed",
            ("workflow_dispatch", "refs/heads/main"): "--changed-since=last-passed",
            ("workflow_dispatch", "refs/heads/feature"): "--changed-since=refs/remotes/origin/main",
            ("release", "refs/tags/v1.0.0"): "--changed-since=last-passed",
            ("merge_group", QUEUE): "--changed-since=refs/remotes/origin/main",
        }
        for (event, ref), base in expected.items():
            with self.subTest(event=event, ref=ref):
                self.assertEqual(action.mode_arguments("changed", "", event, ref, "main"), [base])

    def test_full_is_full_on_every_event(self):
        for event, ref in (
            ("pull_request", "refs/pull/12/merge"),
            ("push", "refs/heads/main"),
            ("schedule", "refs/heads/main"),
            ("workflow_dispatch", "refs/heads/feature"),
            ("release", "refs/tags/v1.0.0"),
        ):
            with self.subTest(event=event):
                self.assertEqual(action.mode_arguments("full", "", event, ref, "main"), ["--full"])

    def test_changed_since_given_wins_over_the_event(self):
        self.assertEqual(
            action.mode_arguments("changed", "refs/tags/v1.2.0", "pull_request", "refs/pull/12/merge", "main"),
            ["--changed-since=refs/tags/v1.2.0"],
        )

    def test_full_is_full_whatever_the_event(self):
        self.assertEqual(
            action.mode_arguments("full", "main", "pull_request", "refs/pull/12/merge", "main"), ["--full"]
        )

    def test_refuses_a_mode_it_does_not_know_auto_among_them(self):
        for mode in ("fast", "auto"):
            with self.subTest(mode=mode), self.assertRaises(action.Refused):
                action.mode_arguments(mode, "", "schedule", "refs/heads/main", "main")



SHA = "c" * 40

FORK_NAMED_MAIN = {
    "pull_request": {
        "number": 14,
        "base": {"sha": "b" * 40, "ref": "main"},
        "head": {"sha": "d" * 40, "ref": "main", "repo": {"full_name": "someone/gate"}},
    },
    "repository": {"full_name": "octo/gate", "default_branch": "main"},
}


class RefShapes(unittest.TestCase):
    """Each ref a run can be under reads its base from a fully qualified ref, a full SHA or a keyword, or refuses."""

    def test_a_fork_pull_request_from_a_branch_named_like_the_default_runs_from_its_last_run_and_keeps_no_ledger(self):
        ref = "refs/pull/14/merge"
        kept = action.ledgers("pull_request", ref, FORK_NAMED_MAIN, "main", cache=True)
        self.assertEqual(
            action.mode_arguments("changed", "", "pull_request", ref, "main"), ["--changed-since=last-run"]
        )
        self.assertEqual(action.scope("pull_request", ref, FORK_NAMED_MAIN), "refs/pull/14")
        self.assertEqual((kept["own_dir"], kept["save"]), ("", "false"))
        self.assertEqual(kept["default_dir"], ".mutation-gate/ledger/refs/heads/main")

    def test_a_push_to_the_default_branch_runs_from_the_last_commit_that_passed_under_its_own_scope(self):
        self.assertEqual(
            action.mode_arguments("changed", "", "push", "refs/heads/main", "main"), ["--changed-since=last-passed"]
        )
        self.assertEqual(action.scope("push", "refs/heads/main", {}), "refs/heads/main")

    def test_a_push_to_another_branch_runs_from_the_fetched_default_branch_by_its_full_name(self):
        self.assertEqual(
            action.mode_arguments("changed", "", "push", "refs/heads/origin/main", "main"),
            ["--changed-since=refs/remotes/origin/main"],
        )

    def test_a_tag_named_like_a_branch_is_no_branch_s_scope_and_runs_from_the_last_commit_that_passed(self):
        self.assertEqual(
            action.mode_arguments("changed", "", "push", "refs/tags/main", "main"), ["--changed-since=last-passed"]
        )
        self.assertIsNone(action.scope("push", "refs/tags/main", {}))
        self.assertEqual(action.ledgers("push", "refs/tags/main", {}, "main", cache=True)["save"], "false")

    def test_a_merge_group_runs_from_its_queue_s_base_and_keeps_no_ledger(self):
        ref = "refs/heads/gh-readonly-queue/main/pr-12-" + SHA
        base = action.queue_base({"merge_group": {"base_sha": "f" * 40, "head_ref": "refs/heads/main"}})
        self.assertEqual(
            action.mode_arguments("changed", "", "merge_group", ref, "main", base), [f"--changed-since={'f' * 40}"]
        )
        self.assertEqual(action.queue_base({}), "")
        self.assertEqual(action.queue_base({"merge_group": {"base_sha": 7}}), "")

    def test_a_merge_group_whose_base_is_no_full_sha_runs_from_the_fetched_default_branch_by_its_full_name(self):
        ref = "refs/heads/gh-readonly-queue/main/pr-12-" + SHA
        for base in ("", "main", "origin/main", "-f", "f" * 39):
            with self.subTest(base=base):
                self.assertEqual(
                    action.mode_arguments("changed", "", "merge_group", ref, "main", base),
                    ["--changed-since=refs/remotes/origin/main"],
                )
        self.assertEqual(
            action.mode_arguments("changed", "", "push", "refs/heads/feature", "main", "f" * 40),
            ["--changed-since=refs/remotes/origin/main"],
        )
        self.assertIsNone(action.scope("merge_group", ref, {}))
        self.assertEqual(action.ledgers("merge_group", ref, {}, "main", cache=True)["save"], "false")

    def test_a_branch_named_like_a_sha_is_a_branch_and_runs_from_the_fetched_default_branch(self):
        ref = "refs/heads/" + SHA
        self.assertEqual(
            action.mode_arguments("changed", "", "push", ref, "main"), ["--changed-since=refs/remotes/origin/main"]
        )
        self.assertEqual(action.scope("push", ref, {}), ref)

    def test_a_ref_that_is_no_valid_ref_is_refused(self):
        for ref in (
            "refs/heads/-main",
            "refs/heads/a..b",
            "refs/heads/a@{1}",
            "refs/heads/a~1",
            "refs/heads/a:b",
            "refs/heads/a\tb",
            "refs/heads/a//b",
            "refs/heads/main.",
        ):
            with self.subTest(ref=ref), self.assertRaises(action.Refused):
                action.mode_arguments("changed", "", "push", ref, "main")

    def test_a_default_branch_that_is_no_branch_name_is_refused(self):
        for name in ("-main", "a..b", "main^", "main~1", "a:b", "@", "main.lock", "a b"):
            with self.subTest(name=name), self.assertRaises(action.Refused):
                action.mode_arguments("changed", "", "push", "refs/heads/feature", name)

    def test_an_unknown_default_branch_makes_a_branch_run_full(self):
        self.assertEqual(action.mode_arguments("changed", "", "push", "refs/heads/feature", ""), ["--full"])

    def test_a_given_base_is_a_full_sha_a_fully_qualified_ref_or_a_keyword(self):
        for given in (SHA, "e" * 64, "refs/tags/v1.2.0", "refs/heads/release/1.x", "last-passed", "last-run"):
            with self.subTest(given=given):
                self.assertEqual(
                    action.mode_arguments("changed", given, "push", "refs/heads/main", "main"),
                    [f"--changed-since={given}"],
                )
        for given in ("v1.2.0", "origin/main", "main", "-x", "--full", "refs/heads/a..b", "c" * 39, "C" * 40, "HEAD~1"):
            with self.subTest(given=given), self.assertRaises(action.Refused):
                action.mode_arguments("changed", given, "push", "refs/heads/main", "main")

    def test_an_option_that_spans_lines_is_refused(self):
        self.assertEqual(action.one_line(["--config=a.json", "--budget=10m"]), ["--config=a.json", "--budget=10m"])
        for options in (["--config=a.json\n--full"], ["--budget=1\r"], ["--runner=pest\0"]):
            with self.subTest(options=options), self.assertRaises(action.Refused):
                action.one_line(options)

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


if __name__ == "__main__":
    unittest.main()
