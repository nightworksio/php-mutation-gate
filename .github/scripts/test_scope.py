"""The deciding half of scope.py: python3 -m unittest discover .github/scripts"""
import json
import re
import unittest

import scope


def legs(found: dict[str, str]) -> list[str]:
    return sorted(json.loads(found["runners"]))


class Outputs(unittest.TestCase):
    def test_runs_every_gate_on_a_push_a_schedule_or_without_a_base(self):
        for event, paths in (("push", ["README.md"]), ("schedule", []), ("pull_request", None)):
            found = scope.outputs(event, paths)
            self.assertEqual((found["code"], found["warm"], found["site"]), ("true", "true", "true"))
            self.assertEqual(json.loads(found["runners"]), list(scope.RUNNERS))

    def test_runs_no_gate_for_documentation(self):
        documentation = [".docs/guide/concepts/holding-tests.md", "README.md", "LICENSE", ".github/workflows/pr.yml"]
        found = scope.outputs("pull_request", documentation)
        self.assertEqual(found, {"code": "false", "warm": "false", "runners": "[]", "site": "true"})

    def test_runs_no_php_gate_for_the_docs_site(self):
        found = scope.outputs("pull_request", [".site/astro.config.mjs", ".site/package-lock.json"])
        self.assertEqual(found, {"code": "false", "warm": "false", "runners": "[]", "site": "true"})

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
            self.assertEqual(scope.outputs("pull_request", [path])["site"], "true", path)
        for path in ("src/Core/Report/Overview.php", "CONTRIBUTING.md", ".github/workflows/pr.yml", "tests/Docs/TroubleshootingTest.php"):
            self.assertEqual(scope.outputs("pull_request", [path])["site"], "false", path)

    def test_reads_the_documentation_the_suites_read_as_code(self):
        read = (
            "ARCHITECTURE.md",
            ".docs/reference/config.md",
            ".docs/guide/ci/github.md",
            ".docs/guide/troubleshooting.md",
            ".github/scripts/evidence.py",
        )
        for path in read:
            self.assertEqual(scope.outputs("pull_request", [path])["code"], "true", path)

    def test_runs_neither_warm_workers_nor_a_leg_for_code_no_runner_runs_through(self):
        found = scope.outputs("pull_request", ["src/Core/Report/Overview.php", "tests/Unit/Core/Report/X.php"])
        self.assertEqual(found, {"code": "true", "warm": "false", "runners": "[]", "site": "false"})

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
            self.assertEqual(legs(scope.outputs("pull_request", [path])), expected, path)

    def test_runs_every_runner_leg_for_the_runner_contract_itself_and_its_other_fixtures(self):
        runners = ["codeception", "infection-12", "infection-13", "pest", "phpspec", "phpunit", "testo"]
        for path in ("tests/Contract/Runner/KillsTest.php", "tests/Contract/Runner/stall/a.php"):
            self.assertEqual(legs(scope.outputs("pull_request", [path])), runners, path)

    def test_runs_every_leg_and_warm_workers_for_what_every_runner_shares(self):
        shared = (
            "src/Core/Runner/Request.php",
            "src/Port/Runner.php",
            "src/Adapter/Process/Running.php",
            "src/Mutator/Engine/Source.php",
            "composer.lock",
            ".github/workflows/ci.yml",
        )
        for path in shared:
            found = scope.outputs("pull_request", [path])
            self.assertEqual(legs(found), sorted(scope.RUNNERS), path)
            self.assertEqual(found["warm"], "true", path)

    def test_runs_warm_workers_for_the_phpunit_runner_the_worker_or_its_measure(self):
        measured = (
            "src/Adapter/Native/Warm/Worker.php",
            "src/Adapter/PhpUnit/Warm/PhpUnitBoot.php",
            "bin/mutation-gate-worker",
            "tests/Contract/Runner/phpunit-fixture/composer.lock",
            ".github/scripts/warm_measure.py",
            ".github/workflows/end-to-end.yml",
        )
        for path in measured:
            self.assertEqual(scope.outputs("pull_request", [path])["warm"], "true", path)
        self.assertEqual(scope.outputs("pull_request", ["src/Adapter/Pest/Pest.php"])["warm"], "false")
        self.assertEqual(scope.outputs("pull_request", ["src/Adapter/PhpSpec/Launch.php"])["warm"], "false")

    def test_takes_a_file_for_a_directory_only_where_it_is_one(self):
        self.assertEqual(legs(scope.outputs("pull_request", ["src/Adapter/PestLike.php"])), [])
        self.assertEqual(legs(scope.outputs("pull_request", ["composer.json.bak"])), [])


# The jobs of ci.yml that run whatever the paths, or by an output that is no PHP gate's.
UNGATED = ("scope", "scripts", "hygiene", "security", "site", "rules", "sonar", "gate", "evidence")

GATED_CI = """jobs:
  scope:
    runs-on: ubuntu-latest
    steps:
      - run: python3 .github/scripts/scope.py pull_request
  tests:
    needs: scope
    if: ${{ needs.scope.outputs.code == 'true' }}
    steps:
      - run: python3 .github/scripts/evidence.py tests
  contract:
    if: ${{ needs.scope.outputs.runners != '[]' }}
    steps:
      - run: python3 .github/scripts/legs.py
  end-to-end:
    if: ${{ needs.scope.outputs.code == 'true' }}
    uses: ./.github/workflows/end-to-end.yml
  site:
    if: ${{ needs.scope.outputs.site == 'true' }}
    steps:
      - if: ${{ needs.scope.outputs.code == 'true' }}
        run: python3 .github/scripts/site_only.py
"""

END_TO_END = """jobs:
  warm:
    steps:
      - run: python3 .github/scripts/warm_measure.py
  hooks:
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
    "site_only.py": "",
    "hook_managers.py": "",
    "decisions_index.py": "import re\n",
}


class Scripts(unittest.TestCase):
    def test_takes_as_code_each_script_a_php_gate_job_runs_and_every_script_those_import(self):
        found = scope.gate_scripts({"ci.yml": GATED_CI, "end-to-end.yml": END_TO_END, "hooks.yml": HOOKS}, SCRIPTS)
        self.assertEqual(sorted(found),
                         ["deeper.py", "evidence.py", "hook_managers.py", "legs.py", "shared.py", "warm_measure.py"])

    def test_takes_no_script_a_job_runs_whatever_the_paths_or_by_the_site_s_output_or_a_step_s_own_if(self):
        found = scope.gate_scripts({"ci.yml": GATED_CI, "end-to-end.yml": END_TO_END}, SCRIPTS)
        self.assertFalse({"scope.py", "site_only.py", "decisions_index.py"} & found)

    def test_runs_no_php_gate_for_a_script_only_the_scripts_job_runs_or_a_script_s_test(self):
        for path in (".github/scripts/decisions_index.py", ".github/scripts/test_decisions_index.py",
                     ".github/scripts/bench_publish.py", ".github/scripts/scope.py", ".github/scripts/unit.py",
                     ".github/scripts/test_evidence.py"):
            self.assertEqual(scope.outputs("pull_request", [path]),
                             {"code": "false", "warm": "false", "runners": "[]", "site": "false"}, path)

    def test_runs_the_php_gates_for_every_script_a_php_gate_job_of_this_repository_runs(self):
        run = re.compile(r"\.github/scripts/[a-z0-9_]+\.py")
        ci = (scope.ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        gated = [job for job in scope.jobs(ci) if job.split(":", 1)[0].strip() not in UNGATED]
        end_to_end = (scope.ROOT / ".github/workflows/end-to-end.yml").read_text(encoding="utf-8")
        named = {path for text in [*gated, end_to_end] for path in run.findall(text)}
        self.assertGreaterEqual(named, {".github/scripts/evidence.py", ".github/scripts/alone.py",
                                        ".github/scripts/warm_measure.py", ".github/scripts/hook_managers.py"})
        for path in sorted(named):
            self.assertEqual(scope.outputs("pull_request", [path])["code"], "true", path)


# A workflow of three jobs: the scope job, one gated by `code` and one by `runners`, and one no output gates.
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
    if: ${{ needs.scope.outputs.code == 'true' }}
    runs-on: ubuntu-latest
    steps:
      - run: composer analyse

  legs:
    needs: scope
    if: ${{ needs.scope.outputs.runners != '[]' }}
    runs-on: ubuntu-latest
    steps:
      - run: composer contract

  evidence:
    needs: [scope, checks, legs]
    runs-on: ubuntu-latest
    steps:
      - run: python3 .github/scripts/evidence.py
"""

CI_PATH = ".github/workflows/ci.yml"
E2E_PATH = ".github/workflows/end-to-end.yml"
NOTHING = {"code": "false", "warm": "false", "runners": "[]", "site": "false"}
EVERYTHING = {"code": "true", "warm": "true", "runners": json.dumps(list(scope.RUNNERS)), "site": "true"}


def ci_changed(after: str) -> dict[str, str]:
    return scope.outputs("pull_request", [CI_PATH], {CI_PATH: (WORKFLOW, after)})


class Workflows(unittest.TestCase):
    def test_runs_nothing_for_a_removed_job_or_another_job_s_needs(self):
        removed = WORKFLOW.replace("  legs:\n", "  gone:\n", 1).split("  gone:\n")[0] + WORKFLOW.split("  evidence:\n")[1].join(["  evidence:\n", ""])
        self.assertEqual(ci_changed(removed.replace("needs: [scope, checks, legs]", "needs: [scope, checks]")), NOTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("needs: [scope, checks, legs]", "needs: [scope, checks]")), NOTHING)

    def test_runs_nothing_for_a_comment_or_a_blank_line(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("  checks:\n", "  # The static checks.\n\n  checks:\n")), NOTHING)

    def test_runs_the_gates_a_changed_or_added_job_reads(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("composer analyse", "composer analyse --memory-limit=2G")),
                         {"code": "true", "warm": "false", "runners": "[]", "site": "false"})
        self.assertEqual(legs(ci_changed(WORKFLOW.replace("composer contract", "composer contract -v"))), sorted(scope.RUNNERS))
        added = WORKFLOW + "\n  docs:\n    if: ${{ needs.scope.outputs.site == 'true' }}\n    steps:\n      - run: npm run build\n"
        self.assertEqual(ci_changed(added), {"code": "false", "warm": "false", "runners": "[]", "site": "true"})

    def test_runs_every_gate_for_what_every_job_shares_or_the_scope_job(self):
        self.assertEqual(ci_changed(WORKFLOW.replace("PHP_VERSION: '8.5'", "PHP_VERSION: '8.6'")), EVERYTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("on:\n  pull_request:", "on:\n  pull_request:\n  push:")), EVERYTHING)
        self.assertEqual(ci_changed(WORKFLOW.replace("scope.py", "scope.py --verbose")), EVERYTHING)

    def test_runs_every_gate_for_a_workflow_it_could_not_read_at_the_base(self):
        self.assertEqual(scope.outputs("pull_request", [CI_PATH]), EVERYTHING)
        self.assertEqual(scope.outputs("pull_request", [CI_PATH], {}), EVERYTHING)

    def test_runs_the_warm_workers_for_the_end_to_end_job_that_reads_warm_and_the_php_gates_for_any_other(self):
        before = "on:\n  workflow_call:\n\njobs:\n  warm-workers:\n    if: inputs.warm\n    steps:\n      - run: a\n\n  hooks:\n    steps:\n      - run: b\n"
        warm = scope.outputs("pull_request", [E2E_PATH], {E2E_PATH: (before, before.replace("run: a", "run: c"))})
        hooks = scope.outputs("pull_request", [E2E_PATH], {E2E_PATH: (before, before.replace("run: b", "run: c"))})
        self.assertEqual((warm["code"], warm["warm"]), ("true", "true"))
        self.assertEqual((hooks["code"], hooks["warm"]), ("true", "false"))

    def test_runs_no_php_gate_for_the_removal_of_the_macos_job(self):
        head = (scope.ROOT / CI_PATH).read_text(encoding="utf-8")
        base = head.replace("  # The gate's PHAR", f"{MACOS_JOB}\n  # The gate's PHAR", 1).replace("lowest, phar,", "lowest, macos, phar,", 1)
        paths = [CI_PATH, ".github/gates.json", ".docs/decisions/0053-ci-runs-the-suite-on-linux-alone-and-macos-is-best-effort.md", "README.md"]
        found = scope.outputs("pull_request", paths, {CI_PATH: (base, head)})
        self.assertNotEqual(base, head)
        self.assertEqual((found["code"], found["runners"], found["warm"]), ("false", "[]", "false"))


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
