"""installs.py, and the steps of gate_action.py that install the gate and build its judge, run against fakes of
git, gh and gpg so no test reaches a real release: python3 -m unittest discover .github/scripts"""
import hashlib
import io
import json
import os
import subprocess
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

import action_script  # puts the action's scripts on the import path
import gate_action as action
import installs

PULL = {"pull_request": {"base": {"sha": "b" * 40}, "head": {"sha": "h" * 40}}}


class Installed(unittest.TestCase):
    def test_the_package_itself_runs_its_own_bin(self):
        self.assertEqual(installs.binary({"name": "nightworksio/mutation-gate"}), "bin/mutation-gate")
        self.assertEqual(installs.binary({"name": "acme/shop"}), "vendor/bin/mutation-gate")

    def test_a_line_is_the_major_or_while_it_is_0_the_major_and_minor(self):
        self.assertEqual(installs.line_of("v0.1.0"), "0.1")
        self.assertEqual(installs.line_of("0.12.3"), "0.12")
        self.assertEqual(installs.line_of("v1.4.0"), "1")
        self.assertEqual(installs.line_of("2.0.0"), "2")

    def test_accepts_the_same_line_and_a_development_version(self):
        for installed in ("0.1.0", "v0.1.7", "dev-main", "0.1.x-dev"):
            self.assertIsNone(installs.version_refusal(installed, "0.1"))
        self.assertIsNone(installs.version_refusal("1.4.0", "1"))

    def test_reads_the_gates_version_from_what_composer_installed(self):
        installed = {"packages": [{"name": "acme/other", "version": "2.0.0"}, {"name": installs.GATE, "version": "v0.1.3"}]}
        self.assertEqual(installs.installed_version(installed), "v0.1.3")
        self.assertEqual(installs.installed_version({"packages": [{"name": "acme/other", "version": "2.0.0"}]}), "")
        self.assertEqual(installs.installed_version({}), "")
        self.assertEqual(installs.installed_version({"packages": {"name": installs.GATE}}), "")
        self.assertEqual(installs.installed_version({"packages": [{"name": installs.GATE, "version": 1}]}), "")

    def test_refuses_a_project_that_does_not_install_the_gate(self):
        self.assertIn("does not install", installs.version_refusal("", "0.1"))

    def test_refuses_another_line(self):
        self.assertIn("^0.1", installs.version_refusal("0.2.0", "0.1"))
        self.assertIn("1.0.0", installs.version_refusal("1.0.0", "0.1"))
        self.assertIn("2.0.0", installs.version_refusal("2.0.0", "1"))


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
    f"[GNUPG:] VALIDSIG {installs.RELEASE_FINGERPRINT} 2026-10-31 1793106457 0 4 0 22 10 00 {installs.RELEASE_FINGERPRINT}\n"
)
OTHER = GOOD.replace(installs.RELEASE_FINGERPRINT, "F" * 40)


class Install(unittest.TestCase):
    def test_auto_runs_the_project_s_copy_where_it_requires_the_gate_and_the_phar_where_it_does_not(self):
        for manifest in ({"require": {installs.GATE: "^0.1"}}, {"require-dev": {installs.GATE: "^0.1"}}, {"name": installs.GATE}):
            self.assertEqual("composer", installs.install("auto", manifest))
        self.assertEqual("phar", installs.install("auto", {"require-dev": {"phpunit/phpunit": "^13"}}))

    def test_composer_and_phar_are_as_asked(self):
        self.assertEqual("composer", installs.install("composer", {}))
        self.assertEqual("phar", installs.install("phar", {"require": {installs.GATE: "^0.1"}}))

    def test_refuses_a_way_it_does_not_know(self):
        with self.assertRaises(installs.Refused) as refused:
            installs.install("docker", {})
        self.assertIn("auto, composer or phar, not docker", str(refused.exception))


class ReleaseTag(unittest.TestCase):
    def test_the_ref_is_github_s_or_the_last_part_of_the_path_it_fetched_the_action_into(self):
        self.assertEqual("v0.1", installs.action_ref("v0.1", "/runner/_actions/nightworksio/php-mutation-gate/main"))
        self.assertEqual("v0.1.1", installs.action_ref("", "/runner/_actions/nightworksio/php-mutation-gate/v0.1.1/"))

    def test_reads_each_tag_s_commit_an_annotated_tag_by_the_commit_it_points_at(self):
        self.assertEqual(
            {"v0.1.0": "1" * 40, "v0.1.1": "2" * 40, "v0.1": "2" * 40},
            installs.remote_tags(LISTED),
        )

    def test_names_the_release_a_release_tag_a_line_s_tag_or_a_commit_names(self):
        tags = installs.remote_tags(LISTED)
        self.assertEqual("v0.1.0", installs.release_tag("v0.1.0", tags, "0.1"))
        self.assertEqual("v0.1.1", installs.release_tag("v0.1", tags, "0.1"))
        self.assertEqual("v0.1.1", installs.release_tag("2" * 40, tags, "0.1"))

    def test_names_the_highest_release_at_one_commit(self):
        self.assertEqual("v0.1.10", installs.release_tag("4" * 40, {"v0.1.9": "4" * 40, "v0.1.10": "4" * 40}, "0.1"))

    def test_refuses_a_ref_that_is_no_release_s_tag_or_commit(self):
        tags = installs.remote_tags(LISTED)
        for ref in ("main", "3" * 40, "v0.2.0", "v0.2", ""):
            with self.subTest(ref=ref), self.assertRaises(installs.Refused) as refused:
                installs.release_tag(ref, tags, "0.1")
            self.assertIn("is no release's tag or commit", str(refused.exception))
            self.assertIn("# v0.1.<patch>", str(refused.exception))
            self.assertIn(f"or require {installs.GATE} with Composer, and the action runs that copy.", str(refused.exception))


class Signature(unittest.TestCase):
    def test_accepts_a_good_signature_by_the_release_key(self):
        self.assertTrue(installs.signed_by(GOOD, installs.RELEASE_FINGERPRINT))

    def test_refuses_a_signature_by_another_key_a_bad_one_and_one_by_an_expired_key(self):
        self.assertFalse(installs.signed_by(OTHER, installs.RELEASE_FINGERPRINT))
        self.assertFalse(installs.signed_by(GOOD.replace("GOODSIG", "BADSIG"), installs.RELEASE_FINGERPRINT))
        self.assertFalse(installs.signed_by(GOOD.replace("GOODSIG", "EXPKEYSIG"), installs.RELEASE_FINGERPRINT))
        self.assertFalse(installs.signed_by(GOOD.splitlines()[0], installs.RELEASE_FINGERPRINT))


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
            code, outputs, _ = step("install", directory, {"require-dev": {installs.GATE: "^0.1"}}, INSTALL="auto")

            self.assertEqual((0, {"install": "composer"}), (code, outputs))

    def test_names_the_release_of_the_ref_the_action_is_pinned_to_where_it_does_not(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("install", directory, NOT_REQUIRED, INSTALL="auto", GITHUB_ACTION_REF="v0.1")

            self.assertEqual((0, {"install": "phar", "tag": "v0.1.1"}), (code, outputs), said)

    def test_refuses_where_the_release_tags_cannot_be_listed(self):
        with tempfile.TemporaryDirectory() as directory:
            code, _, said = step("install", directory, NOT_REQUIRED, INSTALL="phar", GITHUB_ACTION_REF="v0.1", GIT_FAILS="1")

            self.assertEqual(2, code)
            self.assertIn(f"::error::The tags of {installs.RELEASES} could not be listed: could not resolve host", said)


class PharStep(unittest.TestCase):
    def test_names_the_downloaded_phar_once_its_signature_and_attestation_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1")

            self.assertEqual(0, code, said)
            self.assertTrue(outputs["gate"].endswith(f"/{installs.PHAR}"))
            self.assertTrue(os.access(outputs["gate"], os.X_OK))
            with open(os.path.join(directory, "asked"), encoding="utf-8") as file:
                asked = file.read().split()
            self.assertEqual(["--repo", installs.RELEASES, "--signer-workflow", installs.RELEASE_WORKFLOW], asked[3:7])
            self.assertEqual(["--source-ref", "refs/tags/v0.1.1"], asked[7:9])

    def test_runs_nothing_whose_signature_is_not_the_release_key_s(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", status=OTHER)

            self.assertEqual((2, {}), (code, outputs))
            self.assertIn(f"The PHAR of v0.1.1 is not signed by the release key {installs.RELEASE_FINGERPRINT}", said)
            self.assertFalse(os.path.exists(os.path.join(directory, "asked")))

    def test_runs_nothing_that_carries_no_attestation_of_the_release_workflow(self):
        with tempfile.TemporaryDirectory() as directory:
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", UNATTESTED="1")

            self.assertEqual((2, {}), (code, outputs))
            self.assertIn(f"carries no attestation of {installs.RELEASE_WORKFLOW}, so it is not run: no attestations found", said)

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
            self.assertIn(f"The project does not install {installs.GATE}", said)

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
        group = {"merge_group": {"base_sha": "g" * 40}}

        self.assertEqual(
            ([["b" * 40, "h" * 40], ["b" * 40]], True),
            action.judge_candidates("merge-base", "pull_request", PULL, installs.RELEASES, "main"),
        )
        self.assertEqual(
            ([["g" * 40, "HEAD"], ["g" * 40]], True),
            action.judge_candidates("merge-base", "merge_group", group, installs.RELEASES, "main"),
        )
        self.assertEqual(
            ([["origin/main", "HEAD"], ["origin/main"]], True),
            action.judge_candidates("merge-base", "push", {}, installs.RELEASES, "main"),
        )

    def test_its_own_history_is_the_gate_s_own_repository_s_alone(self):
        for judge in installs.JUDGE_OWN_HISTORY:
            with self.subTest(judge), self.assertRaises(installs.Refused) as refused:
                action.judge_candidates(judge, "pull_request", PULL, "octo/gate", "main")

            self.assertIn(f"judge: {judge} builds the gate from its own history", str(refused.exception))
            self.assertIn(f"only {installs.RELEASES} names it", str(refused.exception))


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
            "GITHUB_REPOSITORY": installs.RELEASES,
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
            with open(os.path.join(directory, installs.JUDGE_BUILD), "w", encoding="utf-8") as file:
                file.write("")
            git("add", "scripts")
        git("commit", "--quiet", "--allow-empty", "--message=fork")
        fork = git("rev-parse", "HEAD")
        git("checkout", "--quiet", "-b", "change")
        git("commit", "--quiet", "--allow-empty", "--message=change")
        change = git("rev-parse", "HEAD")
        git("checkout", "--quiet", "main")
        os.makedirs(os.path.join(directory, "scripts/judge"), exist_ok=True)
        with open(os.path.join(directory, installs.JUDGE_BUILD), "w", encoding="utf-8") as file:
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
        self.assertIn(f"{fork} builds no judge: it holds no {installs.JUDGE_BUILD}", named[2])

    def test_refuses_where_no_release_can_be_found(self):
        with tempfile.TemporaryDirectory() as directory:
            self.history(directory, build_at_fork=False)

            code, _, said = judged(directory, {}, JUDGE="release", NO_RELEASE="1")

        self.assertEqual(2, code)
        self.assertIn(f"No release of {installs.RELEASES} could be found to judge with: release not found", said)


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
            code, outputs, said = step("phar", directory, NOT_REQUIRED, TAG="v0.1.1", ASSET=installs.JUDGE_PHAR)

            self.assertEqual(0, code, said)
            self.assertTrue(outputs["gate"].endswith(f"/{installs.JUDGE_PHAR}"))
