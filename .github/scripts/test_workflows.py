"""What the action and the reusable workflow run: python3 -m unittest discover .github/scripts"""
import base64
import glob
import io
import json
import os
import re
import subprocess
import tarfile
import tempfile
import textwrap
import unittest

import action_script  # puts the action's script on the import path
import gate_action as action

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
ACTION = os.path.join(ROOT, "action.yml")
WORKFLOW = os.path.join(ROOT, ".github", "workflows", "mutation-gate.yml")
RELEASE = os.path.join(ROOT, ".github", "workflows", "release.yml")
PUBLISH_SCRIPT = os.path.join(ROOT, "resources", "action", "publish.sh")
INSTALL_SCRIPT = os.path.join(ROOT, "resources", "action", "install.sh")

# The manifest and the lock the gate's own copy installs from, from the root of
# the gate's checkout.
KEYED_MANIFEST = "resources/action/keyed/composer.json"
KEYED_LOCK = "resources/action/keyed/composer.lock"

# More than any one argument, and every argument together, may hold on Linux
# (128 KiB) and macOS (1 MiB).
OVER_ANY_ARGUMENT = 1_200_000

FAKE_GH = """#!/bin/sh
case "$*" in
  *"/branches/"*) exit 0 ;;
  *"--method PUT"*"--input -"*) cat > "${BODY}"; exit 0 ;;
  *"--method PUT"*) exit 1 ;;
  *"contents/"*) echo "f" ;;
esac
"""


# A file the action runs from its own repository: GitHub serves the action as
# the archive git makes of it, which leaves out every export-ignore path.
ACTION_FILE = re.compile(r"\$\{GITHUB_ACTION_PATH\}/([^\"'\s]+)")


# A composer that writes where it was asked to install, and from which manifest.
FAKE_COMPOSER = """#!/bin/sh
printf '%s\\n' "COMPOSER=${COMPOSER}" "$@" > "${CALLED}"
"""


FAKE_GH_RELEASE = """#!/bin/sh
case "$*" in
  *"/git/ref/heads/"*) exit 1 ;;
  *"/git/refs"*) exit 0 ;;
  *"graphql --input ${RUNNER_TEMP}/commit.json"*) cp "${RUNNER_TEMP}/commit.json" "${BODY}"; exit 0 ;;
  *"/pulls?head="*) echo 1 ;;
esac
"""


def lines_of(path: str) -> list[str]:
    with open(path, encoding="utf-8") as file:
        return file.read().splitlines()


def indent(line: str) -> int:
    return len(line) - len(line.lstrip())


def block(lines: list[str], start: int) -> list[str]:
    """The line at start and every line indented under it."""
    under = [lines[start]]
    for line in lines[start + 1 :]:
        if line.strip() and indent(line) <= indent(lines[start]):
            break
        under.append(line)
    return under


def job(path: str, name: str) -> list[str]:
    lines = lines_of(path)
    return block(lines, lines.index(f"  {name}:"))


def steps(lines: list[str]) -> list[list[str]]:
    starts = [at for at, line in enumerate(lines) if line.lstrip().startswith(("- name:", "- uses:", "- id:"))]
    return [block(lines, at) for at in starts]


def step(lines: list[str], name: str) -> list[str]:
    return next(found for found in steps(lines) if found[0].strip() == f"- name: {name}")


def env_of(lines: list[str]) -> dict[str, str]:
    """The values the env under the first line names, but those GitHub fills in."""
    at = next(at for at, line in enumerate(lines) if line.strip() == "env:")
    pairs = (line.strip().split(": ", 1) for line in block(lines, at)[1:] if ": " in line and not line.strip().startswith("#"))
    return {key: value.strip("'") for key, value in pairs if not value.startswith("${{")}


def fake(directory: str, gh: str, name: str = "gh") -> str:
    """A directory whose gh, or the command named, is the one given, for PATH."""
    found = os.path.join(directory, "bin")
    os.makedirs(found)
    with open(os.path.join(found, name), "w", encoding="utf-8") as file:
        file.write(gh)
    os.chmod(os.path.join(found, name), 0o755)
    return found


def bigger_than_any_argument() -> str:
    return '{"format": 1, "runs": [' + ",".join(['{"score": 97.5}'] * (OVER_ANY_ARGUMENT // 16)) + "]}"


def script(of_step: list[str]) -> str:
    at = next(at for at, line in enumerate(of_step) if line.strip() == "run: |")
    return textwrap.dedent("\n".join(block(of_step, at)[1:])) + "\n"


def archived() -> set[str]:
    """Every path in the archive git makes of the tracked tree, as GitHub serves the action."""
    tracked = subprocess.run(["git", "stash", "create"], cwd=ROOT, capture_output=True, text=True, check=True)
    tree = tracked.stdout.strip() or "HEAD"
    archive = subprocess.run(["git", "archive", "--format=tar", tree], cwd=ROOT, capture_output=True, check=True)
    with tarfile.open(fileobj=io.BytesIO(archive.stdout)) as tar:
        return set(tar.getnames())


class Archive(unittest.TestCase):
    def test_every_file_the_action_runs_is_in_its_archive(self):
        with open(ACTION, encoding="utf-8") as file:
            run = set(ACTION_FILE.findall(file.read()))

        self.assertTrue(run)
        self.assertEqual(set(), run - archived())

    def test_the_manifest_and_the_lock_the_gate_s_own_copy_installs_from_are_in_its_archive(self):
        self.assertLessEqual({KEYED_MANIFEST, KEYED_LOCK}, archived())


def install(directory: str, gate: str) -> tuple[subprocess.CompletedProcess, str]:
    """install.sh run for the gate's copy at gate, with a composer that only says how it was called."""
    called = os.path.join(directory, "called.txt")
    composer = fake(directory, FAKE_COMPOSER, "composer")
    ran = subprocess.run(
        ["bash", INSTALL_SCRIPT, gate],
        cwd=directory,
        env={**os.environ, "PATH": f"{composer}{os.pathsep}{os.environ['PATH']}", "CALLED": called},
        stdin=subprocess.DEVNULL,
        capture_output=True,
        text=True,
        check=False,
        timeout=60,
    )
    return ran, called


class OwnCopy(unittest.TestCase):
    def test_installs_the_gate_s_own_copy_from_its_lock_with_no_dev_packages_scripts_or_plugins(self):
        with tempfile.TemporaryDirectory() as directory:
            gate = os.path.join(directory, "gate")
            os.makedirs(os.path.dirname(os.path.join(gate, KEYED_LOCK)))
            for path in (KEYED_MANIFEST, KEYED_LOCK):
                with open(os.path.join(gate, path), "w", encoding="utf-8") as file:
                    file.write("{}")
            ran, called = install(directory, gate)

            self.assertEqual(0, ran.returncode, ran.stderr)
            with open(called, encoding="utf-8") as file:
                said = file.read().splitlines()
            self.assertEqual(f"COMPOSER={KEYED_MANIFEST}", said[0])
            self.assertEqual(["install", f"--working-dir={gate}"], said[1:3])
            self.assertLessEqual({"--no-dev", "--no-scripts", "--no-plugins", "--no-interaction"}, set(said[3:]))

    def test_installs_nothing_where_the_lock_is_missing(self):
        with tempfile.TemporaryDirectory() as directory:
            gate = os.path.join(directory, "gate")
            os.makedirs(os.path.dirname(os.path.join(gate, KEYED_MANIFEST)))
            with open(os.path.join(gate, KEYED_MANIFEST), "w", encoding="utf-8") as file:
                file.write("{}")
            ran, called = install(directory, gate)

            self.assertEqual(1, ran.returncode)
            self.assertIn(f"::error::{gate} has no {KEYED_LOCK}", ran.stdout)
            self.assertFalse(os.path.exists(called))

    def test_the_action_and_every_keyed_job_install_the_gate_s_own_copy_with_it(self):
        delivering = step(lines_of(ACTION), "Install the gate's own copy")

        self.assertIn('run: bash "${GITHUB_ACTION_PATH}/resources/action/install.sh" "${GITHUB_ACTION_PATH}"', [line.strip() for line in delivering])
        for name in CREDENTIALED:
            with self.subTest(job=name):
                self.assertIn(
                    'run: bash "${GATE_ACTION}/resources/action/install.sh" "${GATE_ACTION}"',
                    [line.strip() for line in step(job(WORKFLOW, name), "Install the gate")],
                )


class Publish(unittest.TestCase):
    def test_puts_a_file_larger_than_any_argument_may_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            os.makedirs(os.path.join(directory, "publish"))
            trend = bigger_than_any_argument()
            with open(os.path.join(directory, "publish", "trend.json"), "w", encoding="utf-8") as file:
                file.write(trend)
            gh = fake(directory, FAKE_GH)
            body = os.path.join(directory, "body.json")
            publishing = step(job(WORKFLOW, "publish"), "Publish the badge and the trend")
            declared = {**env_of(lines_of(WORKFLOW)), **env_of(publishing)}
            environment = {
                **os.environ,
                **declared,
                "PATH": f"{gh}{os.pathsep}{os.environ['PATH']}",
                "GITHUB_REPOSITORY": "octo/gate",
                "RUNNER_TEMP": directory,
                "BODY": body,
            }

            self.assertIn('run: bash "${GATE_ACTION}/resources/action/publish.sh"', [line.strip() for line in publishing])
            ran = subprocess.run(
                ["bash", PUBLISH_SCRIPT],
                cwd=directory,
                env=environment,
                stdin=subprocess.DEVNULL,
                capture_output=True,
                text=True,
                check=False,
                timeout=60,
            )

            self.assertEqual(0, ran.returncode, ran.stderr)
            with open(body, encoding="utf-8") as file:
                put = json.load(file)
            self.assertEqual(trend, base64.b64decode(put["content"]).decode())
            self.assertEqual(declared["PUBLISHED"], put["branch"])
            self.assertEqual(declared["MESSAGE"], put["message"])
            self.assertEqual("f", put["sha"])


class Release(unittest.TestCase):
    def test_commits_every_file_the_release_wrote_whatever_its_size(self):
        with tempfile.TemporaryDirectory() as temporary:
            tree = os.path.join(temporary, "tree")
            os.makedirs(os.path.join(tree, "resources", "action"))
            written = {".gitignore": "ignored.txt\n", "resources/action/gate_action.py": 'LINE = "0.1"\n'}
            for path, text in written.items():
                with open(os.path.join(tree, path), "w", encoding="utf-8") as file:
                    file.write(text)
            git = ["git", "-c", "user.name=t", "-c", "user.email=t@t", "-c", "commit.gpgsign=false"]
            for command in (["init", "-q"], ["add", "."], ["commit", "-q", "-m", "init"]):
                subprocess.run([*git, *command], cwd=tree, check=True, capture_output=True)
            released = {"CHANGELOG.md": bigger_than_any_argument(), "resources/action/gate_action.py": 'LINE = "0.2"\n'}
            for path, text in {**released, "ignored.txt": "left out\n"}.items():
                with open(os.path.join(tree, path), "w", encoding="utf-8") as file:
                    file.write(text)
            body = os.path.join(temporary, "body.json")
            environment = {
                **os.environ,
                "PATH": f"{fake(temporary, FAKE_GH_RELEASE)}{os.pathsep}{os.environ['PATH']}",
                "GITHUB_REPOSITORY": "octo/gate",
                "GITHUB_REPOSITORY_OWNER": "octo",
                "VERSION": "0.2.0",
                "LINE": "0.2",
                "DEFAULT_BRANCH": "main",
                "RUNNER_TEMP": temporary,
                "BODY": body,
            }

            ran = subprocess.run(
                ["bash", "-c", script(step(lines_of(RELEASE), "Open the release pull request"))],
                cwd=tree,
                env=environment,
                stdin=subprocess.DEVNULL,
                capture_output=True,
                text=True,
                check=False,
                timeout=60,
            )

            self.assertEqual(0, ran.returncode, ran.stderr)
            with open(body, encoding="utf-8") as file:
                additions = json.load(file)["variables"]["input"]["fileChanges"]["additions"]
            committed = {added["path"]: base64.b64decode(added["contents"]).decode() for added in additions}
            self.assertEqual(released, committed)


# A trusted run's rule: one of the trusted events, a default branch's name
# that is not empty, and the run's ref that branch.
RULE = re.compile(
    r"^\(contains\(fromJSON\('\[(?P<events>[^\]]*)\]'\), github\.event_name\)"
    r" && (?P<name>\(.+?\)) != '' && github\.ref == format\('refs/heads/\{0\}', (?P=name)\)\)$"
)

GUARDED = re.compile(r"^(?P<name>[A-Z_]+): \$\{\{ (?P<rule>.+) && secrets\.(?P=name) \|\| '' \}\}$")

# A secret a caller passes to the reusable workflow: under its own name, read
# from the caller's secrets by that name.
PASSED = re.compile(r"^(?P<name>[A-Z_]+): \$\{\{ secrets\.(?P=name) \}\}$")

ENTERED = re.compile(r"^name: \$\{\{ (?P<rule>.+) && 'mutation-gate-store' \|\| '' \}\}$")


def roots(expression: str) -> set[str]:
    """The contexts an expression reads."""
    return set(re.findall(r"(?<![\w.])([A-Za-z_]+)\.", expression))


def rule() -> str:
    """The rule the deliver job hands its first secret on by."""
    return GUARDED.match(next(line.strip() for line in job(WORKFLOW, "deliver") if "secrets." in line))["rule"]


def declared_secrets() -> dict[str, list[str]]:
    """Each secret the reusable workflow declares under workflow_call, with the lines under it."""
    lines = lines_of(WORKFLOW)
    called = block(lines, lines.index("  workflow_call:"))
    under = block(called, called.index("    secrets:"))[1:]
    names = [at for at, line in enumerate(under) if indent(line) == 6 and line.endswith(":")]
    return {under[at].strip()[:-1]: block(under, at)[1:] for at in names}


def passed_secrets(calling: str) -> list[str]:
    """The lines under a call's `secrets:`, none where it passes none."""
    lines = calling.splitlines()
    at = next((at for at, line in enumerate(lines) if line.strip() == "secrets:"), None)
    return [] if at is None else [line.strip() for line in block(lines, at)[1:] if line.strip()]


def jobs(path: str) -> dict[str, list[str]]:
    """Each job of a workflow, by its id."""
    lines = lines_of(path)
    under = block(lines, lines.index("jobs:"))[1:]
    ids = [line.strip()[:-1] for line in under if indent(line) == 2 and line.endswith(":") and "#" not in line]
    return {name: job(path, name) for name in ids}


def runs_project_code(lines: list[str]) -> bool:
    """Whether a job installs the project's dependencies, or runs the action, which does."""
    return any("ramsey/composer-install@" in line or "uses: ./.mutation-gate/workflow" in line for line in lines)


# A variable that holds a credential, or a location a credential is sent to.
CREDENTIAL = re.compile(
    r"^\s*(AWS_[A-Z_]+|MUTATION_GATE_STORE[A-Z_]*|MUTATION_GATE_[A-Z]+_URL|MUTATION_GATE_WEBHOOK_SECRET"
    r"|OTEL_[A-Z_]+|GOOGLE_[A-Z_]+|AZURE_[A-Z_]+|MUTATION_GATE_[A-Z]+_TOKEN):"
)

# The jobs that hold a secret or a token that can write: none runs the project's code.
CREDENTIALED = ("fetch", "deliver-plan", "deliver-survivors", "deliver")

# The credentials Composer installs the project's dependencies with: the one
# secret a job that runs the project's code is handed, in the env of the step
# that installs them, or as the action's input that only that step reads
# (ADR-0029).
INSTALLING = ("COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}", "composer-auth: ${{ secrets.COMPOSER_AUTH }}")


class Secrets(unittest.TestCase):
    def test_a_job_hands_a_secret_on_only_where_one_rule_trusts_the_run(self):
        handed = [
            line.strip()
            for name, lines in jobs(WORKFLOW).items()
            if name != "fetch"
            for line in lines
            if "secrets." in line and line.strip() not in INSTALLING
        ]
        guarded = [GUARDED.match(line) for line in handed]

        self.assertTrue(handed)
        self.assertEqual([], [line for line, match in zip(handed, guarded) if match is None])
        self.assertEqual({rule()}, {match["rule"] for match in guarded})

    def test_fetch_holds_the_read_key_alone(self):
        handed = [line.strip() for line in job(WORKFLOW, "fetch") if "secrets." in line]

        self.assertEqual(2, len(handed))
        self.assertEqual([], [line for line in handed if "secrets.MUTATION_GATE_READ_" not in line])
        self.assertIn("name: mutation-gate-read", [line.strip() for line in job(WORKFLOW, "fetch")])

    def test_the_rule_checks_the_ref_of_every_trusted_event_against_a_name_github_or_the_repository_gives(self):
        trusted = RULE.match(rule())

        self.assertIsNotNone(trusted)
        self.assertEqual(action.TRUSTED, frozenset(re.findall(r'"([a-z_]+)"', trusted["events"])))
        self.assertEqual({"github", "vars"}, roots(trusted["name"]))
        self.assertIn("github.event.repository.default_branch", trusted["name"])

    def test_only_a_trusted_run_enters_the_environment_that_holds_the_secrets(self):
        entering = [
            line.strip()
            for line in lines_of(WORKFLOW)
            if re.match(r"(name|environment):.*mutation-gate-store", line.strip())
        ]

        self.assertEqual(1, len(entering))
        self.assertEqual({rule()}, {ENTERED.match(line)["rule"] for line in entering})
        self.assertIn(entering[0], [line.strip() for line in job(WORKFLOW, "deliver")])

    def test_the_workflow_declares_every_secret_its_jobs_read_and_requires_none(self):
        read = set(re.findall(r"secrets\.([A-Z_]+)", "\n".join(lines_of(WORKFLOW))))
        declared = declared_secrets()

        self.assertEqual(read, set(declared))
        self.assertEqual({"required: false"}, {line for lines in declared.values() for line in map(str.strip, lines) if line.startswith("required:")})

    def test_no_caller_or_example_hands_the_workflow_every_secret(self):
        others = ("vendor", "node_modules", ".git", ".mutation-gate")
        scanned = [
            path
            for pattern in ("**/*.yml", "**/*.yaml", "**/*.md")
            for path in glob.glob(os.path.join(ROOT, pattern), recursive=True, include_hidden=True)
            if not any(f"{os.sep}{other}{os.sep}" in path for other in others)
        ]

        self.assertIn(os.path.normpath(WORKFLOW), map(os.path.normpath, scanned))
        self.assertTrue(any(f"{os.sep}.docs{os.sep}" in path for path in scanned))
        self.assertEqual([], [path for path in scanned if "secrets: inherit" in "\n".join(lines_of(path))])

    def test_the_caller_the_guide_and_the_template_pass_each_secret_by_its_own_name_alone(self):
        guide = "\n".join(lines_of(os.path.join(ROOT, ".docs", "guide", "ci", "github-actions.md")))
        examples = [block for block in guide.split("```") if "/.github/workflows/mutation-gate.yml@" in block]
        template = "\n".join(lines_of(os.path.join(ROOT, "resources", "ci", "github", "sharded.yml")))
        caller = "\n".join(job(os.path.join(ROOT, ".github", "workflows", "ci.yml"), "mutation"))
        declared = set(declared_secrets())

        self.assertTrue(examples)
        for calling in [*examples, template, caller]:
            with self.subTest(calling=calling[:60]):
                passed = passed_secrets(calling)

                self.assertTrue(passed)
                self.assertEqual([], [line for line in passed if PASSED.match(line) is None])
                self.assertLessEqual({PASSED.match(line)["name"] for line in passed}, declared)

    def test_a_run_with_no_default_branch_name_says_so(self):
        name = RULE.match(rule())["name"]
        saying = step(job(WORKFLOW, "deliver"), "Say why this run holds no secrets")

        self.assertIn(f"{name} == ''", saying[1])
        self.assertEqual(saying, steps(job(WORKFLOW, "deliver"))[0])

    def test_the_badge_is_published_only_where_the_same_rule_trusts_the_run(self):
        self.assertIn(rule(), " ".join(line.strip() for line in job(WORKFLOW, "publish")[:8]))


class ProjectCode(unittest.TestCase):
    def test_the_plan_the_shards_and_the_verdict_run_the_project_s_code(self):
        running = sorted(name for name, lines in jobs(WORKFLOW).items() if runs_project_code(lines))

        self.assertEqual(["plan", "shard", "survivors", "verdict"], running)

    def test_no_job_that_runs_the_project_s_code_holds_a_secret_or_a_credential(self):
        for name, lines in jobs(WORKFLOW).items():
            if runs_project_code(lines):
                with self.subTest(job=name):
                    held = [line for line in lines if line.strip() not in INSTALLING]
                    self.assertEqual([], [line for line in held if "secrets." in line or CREDENTIAL.match(line)])
                    self.assertEqual([], [line for line in lines if line.strip().startswith("environment:")])

    def test_the_install_credentials_reach_only_the_steps_that_install_the_project_s_dependencies(self):
        reached = []
        for name, lines in jobs(WORKFLOW).items():
            for found in steps(lines):
                holding = [line.strip() for line in found if "secrets.COMPOSER_AUTH" in line]
                if holding:
                    reached.append((name, found[0].strip(), holding))

        install = "- uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0"
        action_step = "- uses: ./.mutation-gate/workflow # zizmor: ignore[self-repository]"
        self.assertEqual(
            [
                ("plan", install, [INSTALLING[0]]),
                ("shard", action_step, [INSTALLING[1]]),
                ("survivors", install, [INSTALLING[0]]),
                ("verdict", install, [INSTALLING[0]]),
            ],
            reached,
        )

    def test_the_action_hands_its_install_credentials_to_the_install_step_alone(self):
        reading = [found[0].strip() for found in steps(lines_of(ACTION)) if any("inputs.composer-auth" in line for line in found)]

        self.assertEqual(["- name: Install the project's dependencies"], reading)
        self.assertIn(
            "COMPOSER_AUTH: ${{ inputs.composer-auth }}",
            [line.strip() for line in step(lines_of(ACTION), "Install the project's dependencies")],
        )

    def test_no_job_that_runs_the_project_s_code_may_write(self):
        for name, lines in jobs(WORKFLOW).items():
            if runs_project_code(lines):
                with self.subTest(job=name):
                    self.assertEqual([], [line for line in lines if line.strip().endswith(": write")])

    def test_no_step_that_runs_the_project_s_code_or_follows_it_holds_a_token(self):
        for name, lines in jobs(WORKFLOW).items():
            found = steps(lines)
            installed = next((at for at, one in enumerate(found) if "ramsey/composer-install@" in one[0]), len(found))
            late = [one[0].strip() for one in found[installed:] if any("github.token" in line for line in one)]
            with self.subTest(job=name):
                self.assertEqual([], late)

    def test_fetch_writes_the_ledger_where_the_directory_store_reads_it(self):
        self.assertEqual(action.LEDGERS, env_of(lines_of(WORKFLOW))["LEDGERS"])
        self.assertIn('fetch --to="${LEDGERS}"', "\n".join(job(WORKFLOW, "fetch")))

    def test_only_a_deliver_job_may_comment(self):
        commenting = sorted(name for name, lines in jobs(WORKFLOW).items() if "      pull-requests: write" in lines)

        self.assertEqual(["deliver", "deliver-plan", "deliver-survivors"], commenting)

    def test_a_job_that_holds_a_credential_installs_and_runs_nothing_of_the_project(self):
        for name in CREDENTIALED:
            lines = job(WORKFLOW, name)
            checkouts = [one for one in steps(lines) if "actions/checkout@" in one[0]]
            installing = [line.strip() for line in lines if "install.sh" in line or (re.search(r"\bcomposer\b", line) and "run:" in line)]
            with self.subTest(job=name):
                self.assertFalse(runs_project_code(lines))
                self.assertTrue(checkouts)
                self.assertTrue(all("repository: ${{ job.workflow_repository }}" in "\n".join(one) for one in checkouts))
                self.assertEqual(['run: bash "${GATE_ACTION}/resources/action/install.sh" "${GATE_ACTION}"'], installing)
                self.assertEqual([], [line for line in lines if '"${GATE}"' in line])
                self.assertTrue(any('php "${GATE_ACTION}/bin/mutation-gate"' in line for line in lines))


class Shards(unittest.TestCase):
    def test_a_shard_job_checks_out_the_one_commit_it_mutates(self):
        checkouts = [found for found in steps(job(WORKFLOW, "shard")) if "actions/checkout@" in found[0]]

        self.assertTrue(checkouts)
        self.assertFalse(any("fetch-depth: 0" in line for found in checkouts for line in found))

    def test_the_action_holds_the_token_after_the_install_only_in_deliver_which_runs_no_project_code(self):
        found = steps(lines_of(ACTION))
        installed = next(at for at, one in enumerate(found) if "ramsey/composer-install@" in "\n".join(one))
        late = [one for one in found[installed:] if any("github.token" in line for line in one)]

        self.assertEqual(["- name: Deliver"], [one[0].strip() for one in late])
        self.assertIn("      if: inputs.deliver == 'true'", late[0])

    def test_a_step_that_runs_a_shard_holds_no_token(self):
        running = [found for found in steps(lines_of(ACTION)) if any("run --plan=" in line for line in found)]

        self.assertEqual(2, len(running))
        self.assertFalse(any("GITHUB_TOKEN" in line for found in running for line in found))

    def test_a_doomed_shard_fails_its_job_after_its_result_is_kept_and_the_other_shards_run_on(self):
        lines = job(WORKFLOW, "shard")
        found = steps(lines)
        kept = next(at for at, one in enumerate(found) if "actions/upload-artifact@" in one[0])
        stopping = next(at for at, one in enumerate(found) if one[0].strip() == "- name: Fail this shard once it cannot pass")

        self.assertIn("      fail-fast: false", lines)
        self.assertLess(kept, stopping)
        self.assertTrue(any("gate_action.py\" doomed" in line for line in found[stopping]))
        self.assertFalse(any("secrets." in line or "github.token" in line for line in found[stopping]))
        self.assertFalse(runs_project_code(found[stopping]))

    def test_every_job_that_runs_mutants_on_the_plan_has_the_plans_coverage_map(self):
        # Each timeout, kill and mutant out of memory is judged by its
        # unmutated control, timed from the map the plan made, so a job that
        # runs mutants without it cannot judge.
        for name in ("shard", "survivors"):
            with self.subTest(job=name):
                found = steps(job(WORKFLOW, name))
                coverage = [
                    at for at, one in enumerate(found)
                    if "actions/download-artifact@" in one[0] and any("name: mutation-gate-coverage" in line for line in one)
                ]
                running = [
                    at for at, one in enumerate(found)
                    if any(" --plan=" in line or "uses: ./.mutation-gate/workflow" in line for line in one)
                ]

                self.assertTrue(coverage)
                self.assertTrue(running)
                self.assertLess(coverage[0], running[0])



# An artifact's name or pattern, cut where the attempt that keys it begins.
KEYED = re.compile(r"^(mutation-gate-[a-z-]+)-\$\{\{ (github\.run_attempt|needs\.([a-z-]+)\.outputs\.attempt) \}\}")


def artifacts(lines: list[str], action: str) -> list[str]:
    """The name or pattern of each step of a job that uses this artifact action."""
    found = []
    for one in steps(lines):
        if f"actions/{action}-artifact@" in one[0]:
            found += [line.split(": ", 1)[1] for line in one if line.lstrip().startswith(("name: ", "pattern: "))]
    return found


class Attempts(unittest.TestCase):
    def test_every_artifact_one_job_hands_another_is_named_with_the_attempt_its_writer_gives(self):
        # A run whose jobs are all run again keeps the artifacts every earlier
        # attempt wrote, and a name alone finds an earlier attempt's where this
        # one wrote none. The verdict then read results of another plan, or
        # failed downloading an artifact of a job that was run again.
        found = jobs(WORKFLOW)
        keyed_by = {}
        for name, lines in found.items():
            for written in artifacts(lines, "upload"):
                if not written.startswith("mutation-gate-") or written == "mutation-gate-reports":
                    continue
                key = KEYED.match(written)
                with self.subTest(job=name, artifact=written):
                    self.assertIsNotNone(key)
                    by = key.group(3) or name
                    self.assertIn(f"      attempt: ${{{{ github.run_attempt }}}}", found[by])
                    keyed_by[key.group(1)] = by

        self.assertEqual(keyed_by["mutation-gate-result"], "plan")
        for name, lines in found.items():
            needs = next((line for line in lines if line.startswith("    needs:")), "")
            for read in artifacts(lines, "download"):
                if not read.startswith("mutation-gate-"):
                    continue
                key = KEYED.match(read)
                with self.subTest(job=name, artifact=read):
                    self.assertIsNotNone(key)
                    self.assertEqual(key.group(3), keyed_by[key.group(1)])
                    self.assertIn(key.group(3), needs)


class StaleResults(unittest.TestCase):
    def test_the_verdict_judges_no_result_an_earlier_attempt_left_for_a_shard_run_again(self):
        with tempfile.TemporaryDirectory() as directory:
            results = os.path.join(directory, "results")
            os.mkdir(results)
            for shard, attempt in (("1", "2"), ("2", "1"), ("3", "1"), ("5", "two")):
                with open(os.path.join(results, f"{shard}.json"), "w", encoding="utf-8") as file:
                    file.write("{}")
                with open(os.path.join(results, f"{shard}.attempt"), "w", encoding="utf-8") as file:
                    file.write(f"{attempt}\n")
            with open(os.path.join(results, "4.json"), "w", encoding="utf-8") as file:
                file.write("{}")
            jobs = os.path.join(directory, "jobs.json")
            with open(jobs, "w", encoding="utf-8") as file:
                json.dump([
                    {"name": "mutation / shard 1 (src)", "run_attempt": 2},
                    {"name": "mutation / shard 2 (src)", "run_attempt": 1},
                    {"name": "mutation / shard 3 (src)", "run_attempt": 2},
                    {"name": "mutation / shard 4 (src)", "run_attempt": 2},
                    {"name": "mutation / shard 5 (src)", "run_attempt": 1},
                ], file)
            ran = subprocess.run(
                ["python3", os.path.join(ROOT, "resources", "action", "gate_action.py"), "stale"],
                env={**os.environ, "JOBS": jobs, "RESULTS": results},
                capture_output=True,
                text=True,
                check=False,
            )

            self.assertEqual(ran.returncode, 0)
            self.assertEqual(sorted(os.listdir(results)), ["1.json", "2.json"])
            self.assertIn("::error title=Shard re-run wrote no result::Shard 3 ran again in attempt 2", ran.stdout)
            self.assertIn("Shard 4 ran again in attempt 2", ran.stdout)
            self.assertIn("Shard 5 ran again in attempt 1 and wrote no result, so the result attempt 0 wrote", ran.stdout)

    def test_each_shard_keeps_beside_its_result_the_attempt_that_wrote_it(self):
        upload = next(one for one in steps(job(WORKFLOW, "shard")) if "actions/upload-artifact@" in one[0])
        uploaded = [line.strip() for line in upload]
        ran = "\n".join(step(lines_of(ACTION), "Run shard ${{ inputs.shard }}"))

        self.assertIn(".mutation-gate/results/${{ matrix.shard.id }}.attempt", uploaded)
        self.assertIn('echo "${GITHUB_RUN_ATTEMPT}" > ".mutation-gate/results/${SHARD}.attempt"', ran)

    def test_the_verdict_lists_its_attempt_s_jobs_with_the_token_and_removes_stale_results_before_it_judges(self):
        found = steps(job(WORKFLOW, "verdict"))
        listing = [at for at, one in enumerate(found) if any("/attempts/${{ github.run_attempt }}/jobs" in line for line in one)]
        results = [at for at, one in enumerate(found) if any("pattern: mutation-gate-result-" in line for line in one)]
        removing = [at for at, one in enumerate(found) if any('gate_action.py" stale' in line for line in one)]
        judging = [at for at, one in enumerate(found) if one[0].strip() == "- name: Verdict"]

        self.assertEqual(len(listing), 1)
        self.assertTrue(any("GH_TOKEN: ${{ github.token }}" in line for line in found[listing[0]]))
        self.assertTrue(any("continue-on-error: true" in line for line in found[results[0]]))
        self.assertLess(listing[0], results[0])
        self.assertLess(results[0], removing[0])
        self.assertLess(removing[0], judging[0])


def runs_on(name: str) -> str:
    """A job's runner, as the reusable workflow names it from the input named: a label or a JSON list of labels."""
    return f"    runs-on: ${{{{ startsWith(inputs.{name}, '[') && fromJSON(inputs.{name}) || inputs.{name} }}}}"


# The jobs that hold a key or a token that writes, and run none of the
# project's code: they run on a runner of their own.
KEYED_JOBS = (*CREDENTIALED, "publish")


class Runner(unittest.TestCase):
    def test_every_job_runs_on_the_runner_the_caller_names_and_a_keyed_job_on_the_keyed_one(self):
        for name, lines in jobs(WORKFLOW).items():
            with self.subTest(job=name):
                named = runs_on("keyed-runs-on" if name in KEYED_JOBS else "runs-on")
                self.assertEqual([line for line in lines if line.startswith("    runs-on:")], [named])

    def test_both_runners_are_ubuntu_latest_where_the_caller_names_none(self):
        lines = lines_of(WORKFLOW)
        inputs = block(lines, lines.index("    inputs:"))
        for name in ("runs-on", "keyed-runs-on"):
            with self.subTest(input=name):
                named = block(inputs, inputs.index(f"      {name}:"))

                self.assertIn("        type: string", named)
                self.assertIn("        default: ubuntu-latest", named)

    def test_this_repository_s_callers_name_one_runner(self):
        named = set()
        for path in (os.path.join(ROOT, ".github", "workflows", "ci.yml"), RELEASE):
            calls = [
                lines for lines in jobs(path).values()
                if any("uses: ./.github/workflows/mutation-gate.yml" in line for line in lines)
            ]
            self.assertEqual(len(calls), 1, path)
            named |= {line.strip() for line in calls[0] if line.strip().startswith("runs-on:")}

        self.assertEqual(named, {"runs-on: ubuntu-24.04-arm"})


if __name__ == "__main__":
    unittest.main()
