"""gate_action.py: its deciding half, and the steps that fetch and verify the PHAR, run against fakes of git,
gh and gpg so no test reaches a real release: python3 -m unittest discover .github/scripts"""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
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


class Doomed(unittest.TestCase):
    def test_names_the_survivor_a_doomed_shard_stopped_on(self):
        result = {"doomed": {"unit": "src/Money.php", "mutant": "a1b2c3d4e5f6", "tree": "src", "floor": "100%", "why": "tree"}}

        why = action.doomed(result)

        self.assertIsNotNone(why)
        self.assertIn("a1b2c3d4e5f6", why)
        self.assertIn("src/Money.php", why)
        self.assertIn("src below its floor of 100%", why)

    def test_names_nothing_where_the_shard_ran_to_its_end(self):
        self.assertIsNone(action.doomed({"shard": 1, "mutants": []}))
        self.assertIsNone(action.doomed({"doomed": "a1b2c3d4e5f6"}))

    def test_fails_the_job_with_the_gates_exit_code_for_a_failed_verdict(self):
        self.assertEqual(action.EXIT_CODES[action.FAILED], "failed")


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

    def test_names_each_shard_whose_result_an_attempt_before_its_job_s_latest_run_wrote(self):
        written = {"10": 1, "2": 1, "3": 2, "4": 0, "7": 1}
        latest = {"10": 2, "2": 1, "3": 2, "4": 1}

        self.assertEqual(action.stale(written, latest), ["4", "10"])

    def test_says_which_attempt_ran_the_shard_again_and_which_wrote_the_result_left(self):
        why = action.stale_text("4", 1, 2)

        self.assertIn("Shard 4 ran again in attempt 2 and wrote no result", why)
        self.assertIn("the result attempt 1 wrote is not judged", why)


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
            self.assertIn(f"or require {action.GATE} ^{action.LINE} with Composer.", str(refused.exception))


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
    while [ "$#" -gt 0 ]; do [ "$1" = "--dir" ] && dir="$2"; shift; done
    echo phar > "${dir}/mutation-gate.phar"; echo signature > "${dir}/mutation-gate.phar.asc" ;;
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
