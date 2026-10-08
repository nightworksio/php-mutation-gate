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


def fake(directory: str, gh: str) -> str:
    """A directory whose gh is the one given, for PATH."""
    found = os.path.join(directory, "bin")
    os.makedirs(found)
    with open(os.path.join(found, "gh"), "w", encoding="utf-8") as file:
        file.write(gh)
    os.chmod(os.path.join(found, "gh"), 0o755)
    return found


def bigger_than_any_argument() -> str:
    return '{"format": 1, "runs": [' + ",".join(['{"score": 97.5}'] * (OVER_ANY_ARGUMENT // 16)) + "]}"


def script(of_step: list[str]) -> str:
    at = next(at for at, line in enumerate(of_step) if line.strip() == "run: |")
    return textwrap.dedent("\n".join(block(of_step, at)[1:])) + "\n"


class Archive(unittest.TestCase):
    def test_every_file_the_action_runs_is_in_its_archive(self):
        tracked = subprocess.run(["git", "stash", "create"], cwd=ROOT, capture_output=True, text=True, check=True)
        tree = tracked.stdout.strip() or "HEAD"
        archive = subprocess.run(["git", "archive", "--format=tar", tree], cwd=ROOT, capture_output=True, check=True)
        with tarfile.open(fileobj=io.BytesIO(archive.stdout)) as tar:
            archived = set(tar.getnames())
        with open(ACTION, encoding="utf-8") as file:
            run = set(ACTION_FILE.findall(file.read()))

        self.assertTrue(run)
        self.assertEqual(set(), run - archived)


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

            ran = subprocess.run(
                ["bash", "-c", script(publishing)],
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

ENTERED = re.compile(r"^name: \$\{\{ (?P<rule>.+) && 'mutation-gate-store' \|\| '' \}\}$")


def roots(expression: str) -> set[str]:
    """The contexts an expression reads."""
    return set(re.findall(r"(?<![\w.])([A-Za-z_]+)\.", expression))


def rule() -> str:
    """The rule the deliver job hands its first secret on by."""
    return GUARDED.match(next(line.strip() for line in job(WORKFLOW, "deliver") if "secrets." in line))["rule"]


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


class Secrets(unittest.TestCase):
    def test_a_job_hands_a_secret_on_only_where_one_rule_trusts_the_run(self):
        handed = [line.strip() for name, lines in jobs(WORKFLOW).items() if name != "fetch" for line in lines if "secrets." in line]
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

    def test_a_caller_hands_the_workflow_no_secret(self):
        self.assertNotIn("    secrets:", lines_of(WORKFLOW))

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

    def test_the_guide_and_the_template_call_the_workflow_with_no_secrets(self):
        guide = "\n".join(lines_of(os.path.join(ROOT, ".docs", "guide", "ci", "github-actions.md")))
        examples = [block for block in guide.split("```") if "/.github/workflows/mutation-gate.yml@" in block]
        template = "\n".join(lines_of(os.path.join(ROOT, "resources", "ci", "github", "sharded.yml")))

        self.assertTrue(examples)
        self.assertEqual([], [block for block in [*examples, template] if re.search(r"^\s*secrets:", block, re.M)])

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
                    self.assertEqual([], [line for line in lines if "secrets." in line or CREDENTIAL.match(line)])
                    self.assertEqual([], [line for line in lines if line.strip().startswith("environment:")])

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
            composer = [line.strip() for line in lines if re.search(r"\bcomposer\b", line) and "run:" in line]
            with self.subTest(job=name):
                self.assertFalse(runs_project_code(lines))
                self.assertTrue(checkouts)
                self.assertTrue(all("repository: ${{ job.workflow_repository }}" in "\n".join(one) for one in checkouts))
                self.assertEqual(1, len(composer))
                self.assertIn('--working-dir="${GATE_ACTION}" --no-scripts --no-plugins', composer[0])
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


if __name__ == "__main__":
    unittest.main()
