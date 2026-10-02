"""The deciding half of gate_action.py: python3 -m unittest discover .github/scripts"""
import hashlib
import unittest

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
