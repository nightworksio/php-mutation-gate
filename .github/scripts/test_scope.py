"""The deciding half of scope.py: python3 -m unittest discover .github/scripts"""
import json
import unittest

import scope


def legs(found: dict[str, str]) -> list[str]:
    return sorted(json.loads(found["runners"]))


class Outputs(unittest.TestCase):
    def test_runs_every_gate_on_a_push_a_schedule_or_without_a_base(self):
        for event, paths in (("push", ["README.md"]), ("schedule", []), ("pull_request", None)):
            found = scope.outputs(event, paths)
            self.assertEqual((found["code"], found["warm"]), ("true", "true"))
            self.assertEqual(json.loads(found["runners"]), list(scope.RUNNERS))

    def test_runs_no_gate_for_documentation(self):
        documentation = [".docs/guide/concepts/holding-tests.md", "README.md", "LICENSE", ".github/workflows/pr.yml"]
        found = scope.outputs("pull_request", documentation)
        self.assertEqual(found, {"code": "false", "warm": "false", "runners": "[]"})

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
        self.assertEqual(found, {"code": "true", "warm": "false", "runners": "[]"})

    def test_runs_only_the_legs_whose_adapter_or_fixture_changed(self):
        cases = {
            "src/Adapter/Pest/Plugin.php": ["pest"],
            "src/Attribute/Holds.php": ["pest"],
            "tests/Contract/Runner/fixture/src/Money.php": ["pest"],
            "src/Adapter/Infection/Infection.php": ["infection-12", "infection-13"],
            "tests/Contract/Runner/infection-fixture/composer.lock": ["infection-12", "infection-13"],
            "src/Adapter/PhpUnit/PhpUnit.php": ["infection-12", "infection-13", "phpunit"],
            "bin/mutation-gate-worker": ["phpunit"],
            "src/Adapter/PhpStan/PhpStan.php": ["analysers"],
            "src/Core/Analysis/MutantCheck.php": ["analysers"],
            "tests/Contract/StaticChecker/StaticCheckerTest.php": ["analysers"],
        }
        for path, expected in cases.items():
            self.assertEqual(legs(scope.outputs("pull_request", [path])), expected, path)

    def test_runs_every_runner_leg_for_the_runner_contract_itself_and_its_other_fixtures(self):
        runners = ["infection-12", "infection-13", "pest", "phpunit"]
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
            ".github/scripts/scope.py",
        )
        for path in shared:
            found = scope.outputs("pull_request", [path])
            self.assertEqual(legs(found), sorted(scope.RUNNERS), path)
            self.assertEqual(found["warm"], "true", path)

    def test_runs_warm_workers_for_the_phpunit_runner_the_worker_or_its_measure(self):
        measured = (
            "src/Adapter/PhpUnit/Worker.php",
            "bin/mutation-gate-worker",
            "tests/Contract/Runner/phpunit-fixture/composer.lock",
            ".github/scripts/warm_measure.py",
        )
        for path in measured:
            self.assertEqual(scope.outputs("pull_request", [path])["warm"], "true", path)
        self.assertEqual(scope.outputs("pull_request", ["src/Adapter/Pest/Pest.php"])["warm"], "false")

    def test_takes_a_file_for_a_directory_only_where_it_is_one(self):
        self.assertEqual(legs(scope.outputs("pull_request", ["src/Adapter/PestLike.php"])), [])
        self.assertEqual(legs(scope.outputs("pull_request", ["composer.json.bak"])), [])


if __name__ == "__main__":
    unittest.main()
