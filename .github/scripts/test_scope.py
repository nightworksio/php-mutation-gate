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
            "src/Attribute/Holds.php": ["codeception", "pest", "phpspec"],
            "tests/Contract/Runner/fixture/src/Money.php": ["pest"],
            "src/Adapter/Infection/Infection.php": ["infection-12", "infection-13"],
            "tests/Contract/Runner/infection-fixture/composer.lock": ["infection-12", "infection-13"],
            "src/Adapter/Native/NativeRunner.php": ["codeception", "phpspec", "phpunit"],
            "src/Core/Native/Framework.php": ["codeception", "phpspec", "phpunit"],
            "src/Adapter/PhpUnit/PhpUnitFramework.php": ["phpunit"],
            "bin/mutation-gate-worker": ["phpunit"],
            "bin/mutation-gate-pest-worker": ["pest"],
            "bin/mutation-gate-pest-client": ["pest"],
            "src/Adapter/PhpSpec/PhpSpecFramework.php": ["phpspec"],
            "bin/mutation-gate-phpspec": ["phpspec"],
            "tests/Contract/Runner/phpspec-fixture/composer.lock": ["phpspec"],
            "src/Adapter/Codeception/CodeceptionFramework.php": ["codeception"],
            "tests/Contract/Runner/codeception-fixture/composer.lock": ["codeception"],
            "src/Adapter/PhpStan/PhpStan.php": ["analysers"],
            "src/Core/Analysis/MutantCheck.php": ["analysers"],
            "tests/Contract/StaticChecker/StaticCheckerTest.php": ["analysers"],
        }
        for path, expected in cases.items():
            self.assertEqual(legs(scope.outputs("pull_request", [path])), expected, path)

    def test_runs_every_runner_leg_for_the_runner_contract_itself_and_its_other_fixtures(self):
        runners = ["codeception", "infection-12", "infection-13", "pest", "phpspec", "phpunit"]
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


if __name__ == "__main__":
    unittest.main()
