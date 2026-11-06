"""The deciding half of scope.py: python3 -m unittest discover .github/scripts"""
import json
import re
import unittest

import scope


def legs(found: dict[str, str]) -> list[str]:
    return sorted(json.loads(found["runners"]))


def ran(found: dict[str, str]) -> list[str]:
    return json.loads(found["jobs"])


def pull(*paths: str) -> dict[str, str]:
    return scope.outputs("pull_request", list(paths))


# The jobs that judge the program, in ci.yml's order.
PROGRAM = ["alone", "guards", "checks", "tests", "lowest", "phar", "mutation"]
NOTHING = {"jobs": "[]", "runners": "[]", "warm": "false", "hooks": "false"}
EVERYTHING = {"jobs": json.dumps(list(scope.GATED)), "runners": json.dumps(list(scope.RUNNERS)), "warm": "true", "hooks": "true"}


class Outputs(unittest.TestCase):
    def test_runs_every_job_on_a_push_a_schedule_or_without_a_base(self):
        for event, paths in (("push", ["README.md"]), ("schedule", []), ("pull_request", None)):
            self.assertEqual(scope.outputs(event, paths), EVERYTHING)

    def test_runs_only_the_site_for_documentation(self):
        documentation = [".docs/guide/concepts/holding-tests.md", "README.md", "LICENSE", ".github/workflows/pr.yml"]
        self.assertEqual(pull(*documentation), {**NOTHING, "jobs": '["site"]'})
        self.assertEqual(pull(".site/astro.config.mjs", ".site/package-lock.json"), {**NOTHING, "jobs": '["site"]'})
        self.assertEqual(pull("CONTRIBUTING.md"), NOTHING)

    def test_builds_the_site_where_the_docs_the_site_its_workflows_or_a_printed_link_changed(self):
        built = (
            ".docs/guide/README.md",
            ".site/src/theme.css",
            "README.md",
            "src/Core/ThisPackage.php",
            "src/Core/Troubleshooting/Slug.php",
            "src/Core/Report/Term.php",
            "tests/Docs/PrintedLinksTest.php",
            ".github/workflows/site.yml",
            ".github/workflows/pages.yml",
        )
        for path in built:
            self.assertIn("site", ran(pull(path)), path)
        for path in ("src/Core/Report/Overview.php", "CONTRIBUTING.md", ".github/workflows/pr.yml", "tests/Docs/TroubleshootingTest.php"):
            self.assertNotIn("site", ran(pull(path)), path)

    def test_runs_the_suites_alone_for_the_documents_they_read(self):
        for path in (".docs/reference/config.md", ".docs/guide/ci/github.md", ".docs/guide/troubleshooting.md"):
            self.assertEqual([job for job in ran(pull(path)) if job != "site"], ["alone", "tests"], path)
        self.assertEqual(ran(pull("ARCHITECTURE.md")), ["alone", "guards", "tests"])

    def test_runs_the_suites_and_the_self_gate_for_the_action_and_the_reusable_workflow(self):
        for path in ("action.yml", ".github/workflows/mutation-gate.yml"):
            self.assertEqual(pull(path), {**NOTHING, "jobs": '["alone", "tests", "mutation"]'}, path)

    def test_runs_no_php_gate_for_another_gate_s_own_file(self):
        for path in ("typos.toml", "lychee.toml", ".markdownlint.jsonc", "cliff.toml", "bench/jwt.sh"):
            self.assertEqual(pull(path), NOTHING, path)
        for path in ("Dockerfile", ".dockerignore"):
            self.assertEqual(pull(path), {**NOTHING, "jobs": '["image"]'}, path)
        self.assertEqual(pull("sonar-project.properties"), {**NOTHING, "jobs": '["tests"]'})

    def test_runs_every_program_job_for_the_program_and_the_hook_managers_outside_the_suites(self):
        found = pull("src/Core/Report/Overview.php")
        self.assertEqual(ran(found), [*PROGRAM[:6], "end-to-end", "mutation"])
        self.assertEqual((found["runners"], found["warm"], found["hooks"]), ("[]", "false", "true"))
        for path in ("tests/Unit/Core/Report/OverviewTest.php", "phpstan.neon", "mutation-gate.json"):
            found = pull(path)
            self.assertEqual(ran(found)[:6], PROGRAM[:6], path)
            self.assertIn("mutation", ran(found), path)
        self.assertEqual(pull("tests/Unit/Core/Report/OverviewTest.php")["hooks"], "false")

    def test_runs_only_the_legs_whose_adapter_or_fixture_changed(self):
        cases = {
            "src/Adapter/Pest/Plugin.php": ["pest"],
            "src/Cli/PestPlugin.php": ["pest"],
            "src/Adapter/PhpUnit/IssueKillers.php": ["pest", "phpunit"],
            "src/Core/Test/RunIssues.php": ["pest", "phpunit"],
            "src/Attribute/Holds.php": ["codeception", "pest", "phpspec", "testo"],
            "tests/Contract/Runner/fixture/src/Money.php": ["pest"],
            "src/Adapter/Infection/Infection.php": ["infection-12", "infection-13"],
            "tests/Contract/Runner/infection-fixture/composer.lock": ["infection-12", "infection-13"],
            "src/Adapter/Native/NativeRunner.php": ["codeception", "phpspec", "phpunit", "testo"],
            "src/Core/Native/Framework.php": ["codeception", "phpspec", "phpunit", "testo"],
            "src/Core/Coverage/PhpUnitXml.php": ["infection-12", "infection-13", "testo"],
            "src/Adapter/PhpUnit/PhpUnitFramework.php": ["phpunit"],
            "bin/mutation-gate-worker": ["phpunit"],
            "bin/mutation-gate-pest-worker": ["pest"],
            "bin/mutation-gate-pest-client": ["pest"],
            "src/Adapter/PhpSpec/PhpSpecFramework.php": ["phpspec"],
            "bin/mutation-gate-phpspec": ["phpspec"],
            "tests/Contract/Runner/phpspec-fixture/composer.lock": ["phpspec"],
            "src/Adapter/Codeception/CodeceptionFramework.php": ["codeception"],
            "tests/Contract/Runner/codeception-fixture/composer.lock": ["codeception"],
            "src/Adapter/Testo/TestoFramework.php": ["testo"],
            "bin/mutation-gate-testo": ["testo"],
            "tests/Contract/Runner/testo-fixture/composer.lock": ["testo"],
            "src/Adapter/PhpStan/PhpStan.php": ["analysers"],
            "src/Core/Analysis/MutantCheck.php": ["analysers"],
            "tests/Contract/StaticChecker/StaticCheckerTest.php": ["analysers"],
        }
        for path, expected in cases.items():
            self.assertEqual(legs(pull(path)), expected, path)

    def test_runs_every_runner_leg_for_the_runner_contract_itself_and_its_other_fixtures(self):
        runners = ["codeception", "infection-12", "infection-13", "pest", "phpspec", "phpunit", "testo"]
        for path in ("tests/Contract/Runner/KillsTest.php", "tests/Contract/Runner/stall/a.php"):
            self.assertEqual(legs(pull(path)), runners, path)
            self.assertIn("runner-contracts", ran(pull(path)), path)

    def test_runs_every_leg_and_warm_workers_for_what_every_runner_runs_through(self):
        shared = (
            "src/Core/Runner/Request.php",
            "src/Port/Runner.php",
            "src/Adapter/Process/Running.php",
            "src/Mutator/Engine/Source.php",
            "composer.lock",
        )
        for path in shared:
            found = pull(path)
            self.assertEqual(legs(found), sorted(scope.RUNNERS), path)
            self.assertEqual(found["warm"], "true", path)

    def test_runs_every_leg_but_no_warm_workers_for_how_the_legs_run_the_contract_suite(self):
        for path in ("phpunit.xml", ".github/scripts/legs.json"):
            found = pull(path)
            self.assertEqual(legs(found), sorted(scope.RUNNERS), path)
            self.assertEqual(found["warm"], "false", path)

    def test_runs_a_leg_for_the_test_support_its_tests_use_and_no_leg_for_any_other(self):
        listed = {path for leg in scope.LEG_TABLE.values() for path in leg["support"]}
        for runner, leg in scope.LEG_TABLE.items():
            for path in leg["support"]:
                found = pull(path)
                self.assertIn(runner, legs(found), path)
                self.assertEqual(legs(found), sorted(other for other, held in scope.LEG_TABLE.items() if path in held["support"]), path)
                self.assertEqual(found["warm"], "false", path)
        unused = sorted(path.relative_to(scope.ROOT).as_posix() for path in (scope.ROOT / "tests/Support").rglob("*.php"))
        unused = [path for path in unused if path not in listed]
        self.assertNotEqual(unused, [])
        for path in unused:
            self.assertEqual(legs(pull(path)), [], path)

    def test_runs_warm_workers_for_the_phpunit_runner_the_worker_or_its_measure(self):
        measured = (
            "src/Adapter/Native/Warm/Worker.php",
            "src/Adapter/PhpUnit/Warm/PhpUnitBoot.php",
            "bin/mutation-gate-worker",
            "tests/Contract/Runner/phpunit-fixture/composer.lock",
            ".github/scripts/warm_measure.py",
        )
        for path in measured:
            found = pull(path)
            self.assertEqual(found["warm"], "true", path)
            self.assertIn("end-to-end", ran(found), path)
        self.assertEqual(pull("src/Adapter/Pest/Pest.php")["warm"], "false")
        self.assertEqual(pull("src/Adapter/PhpSpec/Launch.php")["warm"], "false")

    def test_takes_a_file_for_a_directory_only_where_it_is_one(self):
        self.assertEqual(legs(pull("src/Adapter/PestLike.php")), [])
        self.assertEqual(legs(pull("composer.json.bak")), [])


class TheImage(unittest.TestCase):
    def test_builds_the_image_where_its_recipe_or_the_phar_s_build_changed_and_nowhere_else(self):
        for path in scope.IMAGE:
            self.assertIn("image", ran(pull(path)), path)
        self.assertNotIn("image", ran(pull("src/Core/Plan/Cut.php")))


class TheWorkflowAgrees(unittest.TestCase):
    def setUp(self):
        self.ci = (scope.ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")

    def test_gates_each_job_it_lists_by_the_jobs_output_and_no_other(self):
        reading = {scope.job_id(job) for job in scope.jobs(self.ci) if "needs.scope.outputs.jobs" in job.split("\n    steps:", 1)[0]}
        self.assertEqual(reading, set(scope.GATED))
        for job in scope.jobs(self.ci):
            if scope.job_id(job) in scope.GATED:
                self.assertIn(f"'{scope.job_id(job)}')", job, scope.job_id(job))
        self.assertEqual(set(scope.INPUTS) | {"runner-contracts", scope.END_TO_END_CALLER}, set(scope.GATED))

    def test_falls_back_to_every_leg_it_knows(self):
        fallback = re.search(r"needs\.scope\.outputs\.runners \|\| '(\[[^']*\])'", self.ci)
        self.assertIsNotNone(fallback)
        self.assertEqual(json.loads(fallback.group(1)), list(scope.RUNNERS))

    def test_gives_each_end_to_end_job_its_own_input(self):
        end_to_end = (scope.ROOT / ".github/workflows/end-to-end.yml").read_text(encoding="utf-8")
        reads = sorted(read for job in scope.jobs(end_to_end) for read in scope.INPUT_READ.findall(job))
        self.assertEqual(reads, sorted(scope.END_TO_END_JOBS))


GATED_CI = """jobs:
  scope:
    runs-on: ubuntu-latest
    steps:
      - run: python3 .github/scripts/scope.py pull_request
  tests:
    needs: scope
    if: ${{ contains(fromJSON(needs.scope.outputs.jobs), 'tests') }}
    steps:
      - run: python3 .github/scripts/evidence.py tests
  runner-contract:
    if: ${{ needs.scope.outputs.runners != '[]' }}
    steps:
      - run: python3 .github/scripts/legs.py
  end-to-end:
    if: ${{ contains(fromJSON(needs.scope.outputs.jobs), 'end-to-end') }}
    uses: ./.github/workflows/end-to-end.yml
"""

END_TO_END = """jobs:
  warm:
    if: inputs.warm
    steps:
      - run: python3 .github/scripts/warm_measure.py
  hooks:
    if: inputs.hooks
    uses: ./.github/workflows/hooks.yml
"""

HOOKS = """jobs:
  managers:
    steps:
      - run: python3 .github/scripts/hook_managers.py
"""

SCRIPTS = {
    "scope.py": "import json\n",
    "evidence.py": "import json\nimport shared\n",
    "shared.py": "from pathlib import Path\nimport deeper\n",
    "deeper.py": "import os\n",
    "legs.py": "",
    "warm_measure.py": "",
    "hook_managers.py": "",
    "decisions_index.py": "import re\n",
}


class Scripts(unittest.TestCase):
    def test_gives_each_job_the_scripts_it_runs_and_every_script_those_import(self):
        found = scope.job_scripts({"ci.yml": GATED_CI, "end-to-end.yml": END_TO_END, "hooks.yml": HOOKS}, SCRIPTS)
        self.assertEqual(sorted(found["tests"]), ["deeper.py", "evidence.py", "shared.py"])
        self.assertEqual(sorted(found["runner-contract"]), ["legs.py"])
        self.assertEqual(sorted(found["end-to-end"]), ["hook_managers.py", "warm_measure.py"])

    def test_gives_each_end_to_end_job_s_scripts_to_the_input_that_runs_it(self):
        found = scope.job_scripts({"ci.yml": GATED_CI, "end-to-end.yml": END_TO_END, "hooks.yml": HOOKS}, SCRIPTS)
        self.assertEqual((sorted(found["warm"]), sorted(found["hooks"])), (["warm_measure.py"], ["hook_managers.py"]))
        self.assertEqual(sorted(found["scope"]), ["scope.py"])

    def test_runs_no_gated_job_for_a_script_only_the_scripts_job_runs_or_a_script_s_test(self):
        for path in (".github/scripts/test_decisions_index.py",
                     ".github/scripts/bench_publish.py", ".github/scripts/scope.py", ".github/scripts/unit.py",
                     ".github/scripts/test_evidence.py"):
            self.assertEqual(pull(path), NOTHING, path)

    def test_runs_each_job_of_this_repository_for_the_scripts_it_runs_and_no_other(self):
        self.assertEqual(ran(pull(".github/scripts/alone.py")), ["alone"])
        self.assertEqual(ran(pull(".github/scripts/decisions_index.py")), ["site"])
        hooks = pull(".github/scripts/hook_managers.py")
        self.assertEqual((ran(hooks), hooks["hooks"], hooks["warm"]), (["end-to-end"], "true", "false"))
        legs_only = pull(".github/scripts/leg_support.py")
        self.assertEqual((ran(legs_only), legs(legs_only)), (["runner-contracts"], sorted(scope.RUNNERS)))
        evidence = pull(".github/scripts/evidence.py")
        self.assertEqual(ran(evidence), [job for job in scope.GATED if job not in ("mutation", "image")])
        self.assertEqual((evidence["warm"], evidence["hooks"]), ("true", "true"))


# A workflow of four jobs: the scope job, one gated by `jobs`, the legs, and one that runs on every pull request.
WORKFLOW = """name: ci

on:
  pull_request:

env:
  PHP_VERSION: '8.5'

jobs:
  scope:
    name: what changed
    runs-on: ubuntu-latest
    steps:
      - run: python3 .github/scripts/scope.py

  checks:
    needs: scope
    if: ${{ contains(fromJSON(needs.scope.outputs.jobs), 'checks') }}
    runs-on: ubuntu-latest
    steps:
      - run: composer analyse

  runner-contract:
    needs: scope
    if: ${{ needs.scope.outputs.runners != '[]' }}
    runs-on: ubuntu-latest
    steps:
      - run: composer contract

  evidence:
    needs: [scope, checks, runner-contract]
    runs-on: ubuntu-latest
    steps:
      - run: python3 .github/scripts/evidence.py
"""

CI_PATH = ".github/workflows/ci.yml"
E2E_PATH = ".github/workflows/end-to-end.yml"


def ci_changed(after: str) -> dict[str, str]:
    return scope.outputs("pull_request", [CI_PATH], {CI_PATH: (WORKFLOW, after)})


class Workflows(unittest.TestCase):
    def test_runs_nothing_for_a_removed_job_or_another_job_s_needs(self):
        removed = WORKFLOW.split("  runner-contract:\n")[0] + "  evidence:\n" + WORKFLOW.split("  evidence:\n")[1]
        self.assertEqual(ci_changed(removed.replace("needs: [scope, checks, runner-contract]", "needs: [scope, checks]")), NOTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("needs: [scope, checks, runner-contract]", "needs: [scope, checks]")), NOTHING)

    def test_runs_nothing_for_a_comment_or_a_blank_line(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("  checks:\n", "  # The static checks.\n\n  checks:\n")), NOTHING)

    def test_runs_the_job_a_change_adds_or_changes_and_no_other(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("composer analyse", "composer analyse --memory-limit=2G")),
                         {**NOTHING, "jobs": '["checks"]'})
        legs_changed = ci_changed(WORKFLOW.replace("composer contract", "composer contract -v"))
        self.assertEqual((legs(legs_changed), ran(legs_changed)), (sorted(scope.RUNNERS), ["runner-contracts"]))
        added = WORKFLOW + "\n  site:\n    if: ${{ contains(fromJSON(needs.scope.outputs.jobs), 'site') }}\n    steps:\n      - run: npm run build\n"
        self.assertEqual(ci_changed(added), {**NOTHING, "jobs": '["site"]'})

    def test_runs_every_job_for_what_every_job_shares_or_the_scope_job(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("PHP_VERSION: '8.5'", "PHP_VERSION: '8.6'")), EVERYTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("on:\n  pull_request:", "on:\n  pull_request:\n  push:")), EVERYTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("scope.py", "scope.py --verbose")), EVERYTHING)

    def test_runs_every_job_for_a_workflow_it_could_not_read_at_the_base(self):
        self.assertEqual(scope.outputs("pull_request", [CI_PATH]), EVERYTHING)
        self.assertEqual(scope.outputs("pull_request", [CI_PATH], {}), EVERYTHING)

    def test_runs_the_end_to_end_job_a_change_moves_and_both_for_what_they_share(self):
        before = ("on:\n  workflow_call:\n\njobs:\n  warm-workers:\n    if: inputs.warm\n    steps:\n      - run: a\n\n"
                  "  hook-managers:\n    if: inputs.hooks\n    steps:\n      - run: b\n")

        def changed(after: str) -> dict[str, str]:
            return scope.outputs("pull_request", [E2E_PATH], {E2E_PATH: (before, after)})

        self.assertEqual(changed(before.replace("run: a", "run: c")), {**NOTHING, "jobs": '["end-to-end"]', "warm": "true"})
        self.assertEqual(changed(before.replace("run: b", "run: c")), {**NOTHING, "jobs": '["end-to-end"]', "hooks": "true"})
        self.assertEqual(changed(before.replace("workflow_call:", "workflow_call:\n    inputs: {}")),
                         {**NOTHING, "jobs": '["end-to-end"]', "warm": "true", "hooks": "true"})

    def test_runs_only_the_site_for_the_removal_of_the_macos_job(self):
        head = (scope.ROOT / CI_PATH).read_text(encoding="utf-8")
        base = head.replace("  # The gate's PHAR", f"{MACOS_JOB}\n  # The gate's PHAR", 1).replace("lowest, phar,", "lowest, macos, phar,", 1)
        paths = [CI_PATH, ".github/gates.json", ".docs/decisions/0053-ci-runs-the-suite-on-linux-alone-and-macos-is-best-effort.md", "README.md"]
        found = scope.outputs("pull_request", paths, {CI_PATH: (base, head)})
        self.assertNotEqual(base, head)
        self.assertEqual(found, {**NOTHING, "jobs": '["site"]'})


# The job that ran the suite on macOS, which ADR-0053 removes.
MACOS_JOB = """  # The suite on macOS, the other platform the gate supports for local use
  # (ADR-0042), against the lock.
  macos:
    name: tests on macOS
    needs: scope
    if: ${{ !cancelled() && (needs.scope.result != 'success' || needs.scope.outputs.code == 'true') }}
    runs-on: macos-latest
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: ${{ env.PHP_VERSION }}
          coverage: none
      - uses: ramsey/composer-install@65e4f84970763564f46a70b8a54b90d033b3bdda # 4.0.0
      - id: suite
        run: |
          mkdir -p evidence/raw
          composer test -- --log-junit=evidence/raw/macos.xml
      # The job's evidence, whatever the steps above did (ADR-0019).
      - name: evidence
        if: ${{ !cancelled() }}
        env:
          STEPS: ${{ toJSON(steps) }}
        run: python3 .github/scripts/evidence.py macos
      - uses: actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9 # v7.0.2
        if: ${{ !cancelled() }}
        with:
          name: evidence-macos
          path: evidence/macos*
          if-no-files-found: error
          retention-days: 7
      - name: what failed
        if: failure()
        run: python3 .github/scripts/gate_summary.py macos
"""


if __name__ == "__main__":
    unittest.main()
