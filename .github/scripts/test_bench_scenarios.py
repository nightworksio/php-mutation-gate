"""The benchmark's scenarios: python3 -m unittest discover .github/scripts"""
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import bench_scenarios

PROJECT = {
    "project": "acme/money",
    "version": "1.0.0",
    "commit": "p" * 40,
    "scenarios": {
        "edit": [
            {"file": "src/Money.php", "rename": "amount", "to": "sum"},
            {"file": "tests/MoneyTest.php", "rename": "money", "to": "paid"},
        ],
        "replay": [
            {"pull": 1, "base": "a" * 40, "merge": "b" * 40},
            {"pull": 2, "base": "a" * 40, "merge": "c" * 40},
            {"pull": 3, "base": "d" * 40, "merge": "e" * 40},
        ],
        "gate": ["gate"],
    },
}


class Deciding(unittest.TestCase):
    def test_takes_the_value_at_a_share_of_the_sorted_values_by_the_nearest_rank(self):
        self.assertEqual(bench_scenarios.percentile([5.0, 1.0, 3.0, 2.0, 4.0], 0.9), 5.0)
        self.assertEqual(bench_scenarios.percentile([float(each) for each in range(1, 21)], 0.9), 18.0)
        self.assertEqual(bench_scenarios.percentile([7.0], 0.9), 7.0)
        self.assertEqual(bench_scenarios.percentile([], 0.9), 0.0)

    def test_owns_each_file_the_preparation_wrote_or_changed_and_none_it_left(self):
        prepared = {"composer.json": b"bench", "src/A.php": b"a", "bench-plain.json": b"{}"}
        pristine = {"composer.json": b"project", "src/A.php": b"a"}
        self.assertEqual(bench_scenarios.owned(prepared, pristine), {"composer.json", "bench-plain.json"})

    def test_writes_every_file_of_a_commit_and_removes_every_other_tracked_one_but_those_it_owns(self):
        written, removed = bench_scenarios.laid(
            tracked={"composer.json", "src/A.php", "src/Gone.php", "tests/ATest.php"},
            tree={"composer.json", "src/A.php", "src/New.php"},
            kept={"composer.json", "tests/ATest.php"},
        )
        self.assertEqual(written, {"src/A.php", "src/New.php"})
        self.assertEqual(removed, {"src/Gone.php"})

    def test_renames_every_use_of_a_variable_and_no_longer_name_it_begins(self):
        text = "<?php foreach ($this->formatters as $formatter) { $claims = $formatter->format($claims); }"
        self.assertEqual(bench_scenarios.renamed(text, "formatter", "each"),
                         "<?php foreach ($this->formatters as $each) { $claims = $each->format($claims); }")

    def test_refuses_a_rename_that_finds_nothing_or_would_join_two_variables(self):
        with self.assertRaisesRegex(ValueError, r"\$gone is not in the text"):
            bench_scenarios.renamed("<?php $a = 1;", "gone", "b")
        with self.assertRaisesRegex(ValueError, r"\$b is already in the text"):
            bench_scenarios.renamed("<?php $a = $b;", "a", "b")

    def test_plans_each_arm_s_runs_in_order(self):
        steps = [
            (step["scenario"], step["at"][:1], step.get("edited", False), step["branch"], step["full"],
             step["timed"], step.get("fresh", False))
            for step in bench_scenarios.plan(PROJECT)
        ]
        self.assertEqual(steps, [
            ("warm", "p", False, "main", True, False, True),
            ("warm", "p", True, "main", True, True, False),
            ("unchanged", "p", True, "main", True, True, False),
            ("replay", "a", False, "main", True, False, True),
            ("replay", "b", False, "pull-1", False, True, False),
            ("replay", "c", False, "pull-2", False, True, False),
            ("replay", "d", False, "main", False, False, False),
            ("replay", "e", False, "pull-3", False, True, False),
        ])

    def test_runs_every_scenario_in_one_part_where_the_replay_is_not_cut(self):
        self.assertEqual(bench_scenarios.parts(PROJECT), ["all"])
        self.assertEqual(bench_scenarios.plan(PROJECT, "all"), bench_scenarios.plan(PROJECT))

    def test_cuts_the_replay_into_consecutive_parts_beside_a_warm_part_each_from_a_full_run_at_its_first_base(self):
        cut = {**PROJECT, "scenarios": {**PROJECT["scenarios"], "replayJobs": 2}}
        steps = {part: [(step["scenario"], step["at"][:1], step["branch"], step["full"], step.get("fresh", False))
                        for step in bench_scenarios.plan(cut, part)]
                 for part in bench_scenarios.parts(cut)}
        self.assertEqual(list(steps), ["warm", "replay-1", "replay-2"])
        self.assertEqual(steps["warm"], [("warm", "p", "main", True, True), ("warm", "p", "main", True, False),
                                         ("unchanged", "p", "main", True, False)])
        self.assertEqual(steps["replay-1"], [
            ("replay", "a", "main", True, True),
            ("replay", "b", "pull-1", False, False),
            ("replay", "c", "pull-2", False, False),
        ])
        self.assertEqual(steps["replay-2"], [
            ("replay", "d", "main", True, True),
            ("replay", "e", "pull-3", False, False),
        ])

    def test_cuts_the_replay_into_no_more_parts_than_it_has_pull_requests(self):
        cut = {**PROJECT, "scenarios": {**PROJECT["scenarios"], "replayJobs": 5}}
        self.assertEqual(bench_scenarios.parts(cut), ["warm", "replay-1", "replay-2", "replay-3"])
        self.assertEqual([[pull["pull"] for pull in bench_scenarios.pulls_of(cut, part)]
                          for part in bench_scenarios.parts(cut)], [[], [1], [2], [3]])
        none = {**PROJECT, "scenarios": {**PROJECT["scenarios"], "replay": [], "replayJobs": 2}}
        self.assertEqual(bench_scenarios.parts(none), ["all"])

    def test_prints_the_parts_and_refuses_to_run_one_the_scenarios_do_not_have(self):
        with tempfile.TemporaryDirectory() as work:
            described = Path(work) / "project.json"
            described.write_text(json.dumps({**PROJECT, "scenarios": {**PROJECT["scenarios"], "replayJobs": 2}}))
            printed = subprocess.run([sys.executable, "bench_scenarios.py", "parts", str(described)],
                                     capture_output=True, text=True, check=True, cwd=Path(__file__).parent)
            refused = subprocess.run([sys.executable, "bench_scenarios.py", "run", str(described), work, work,
                                      "--part", "replay-3"], capture_output=True, text=True,
                                     cwd=Path(__file__).parent)
        self.assertEqual(json.loads(printed.stdout), ["warm", "replay-1", "replay-2"])
        self.assertEqual(refused.returncode, 2)
        self.assertIn("replay-3 is no part of", refused.stderr)

    def test_replays_with_no_pull_request_as_warm_and_unchanged_alone(self):
        project = {**PROJECT, "scenarios": {**PROJECT["scenarios"], "replay": []}}
        self.assertEqual([step["scenario"] for step in bench_scenarios.plan(project)], ["warm", "warm", "unchanged"])

    def test_gives_a_replay_s_median_and_p90_of_the_pulls_that_reached_a_verdict_and_names_the_rest(self):
        runs = [{"seconds": 10.0, "exit": 0}, {"seconds": 30.0, "exit": 1}, {"seconds": 20.0, "exit": 0},
                {"seconds": 99.0, "exit": 2}, {"seconds": 5.0, "exit": "stopped"}]
        self.assertEqual(bench_scenarios.replayed(runs),
                         {"pulls": 5, "judged": 3, "median": 20.0, "p90": 30.0, "otherwise": [2, "stopped"]})

    def test_tabulates_each_arm_s_scenarios(self):
        wall = {"median": 12.0, "min": 11.0, "max": 14.0}
        arms = {
            "gate": {"warm": wall, "unchanged": {"median": 2.0, "min": 2.0, "max": 3.0},
                     "replay": {"pulls": 3, "judged": 2, "median": 4.0, "p90": 6.0, "otherwise": [2]}},
            "infection": {"replay": {"pulls": 3, "judged": 3, "median": 40.0, "p90": 60.0, "otherwise": []}},
        }
        text = bench_scenarios.summary(PROJECT, arms)
        self.assertIn("| gate | 12.0 s (11.0–14.0) | 2.0 s (2.0–3.0) | 4.0 s | 6.0 s | 1 of 3 |", text)
        self.assertIn("| infection | - | - | 40.0 s | 60.0 s | 0 of 3 |", text)
        self.assertIn("the last 3 merged pull requests", text)
        self.assertIn("with the scripted edit, `$amount` renamed `$sum` in `src/Money.php` and `$money` renamed "
                      "`$paid` in `tests/MoneyTest.php`, on the ledger of one without it.", text)


def git(directory: Path, *arguments: str) -> str:
    return subprocess.run(["git", "-C", str(directory), *arguments], check=True, capture_output=True,
                          text=True).stdout.strip()


def commit(directory: Path, files: dict[str, str], message: str) -> str:
    for path in list(git(directory, "ls-files").splitlines()):
        if path not in files:
            (directory / path).unlink()
    for path, text in files.items():
        (directory / path).parent.mkdir(parents=True, exist_ok=True)
        (directory / path).write_text(text)
    git(directory, "add", "--all")
    git(directory, "-c", "user.name=t", "-c", "user.email=t@localhost", "commit", "--quiet", "--message", message)
    return git(directory, "rev-parse", "HEAD")


# A gate that says, for each run, the branch it ran on, the project's commit
# the checkout holds, its options, whether it found its ledger, and the
# preparation's file; and leaves a ledger.
FAKE_GATE = """
import json, pathlib, subprocess, sys
head = subprocess.run(['git', 'log', '-1', '--format=%s'], capture_output=True, text=True).stdout.strip()
branch = subprocess.run(['git', 'branch', '--show-current'], capture_output=True, text=True).stdout.strip()
origin = subprocess.run(['git', 'rev-parse', 'origin/HEAD'], capture_output=True, text=True).stdout.strip()
ledger = pathlib.Path('.mutation-gate/ledger')
said = {'branch': branch, 'head': head, 'options': sys.argv[2:], 'ledger': ledger.read_text() if ledger.exists() else '',
        'owned': pathlib.Path('composer.json').read_text(), 'files': sorted(p.name for p in pathlib.Path('src').glob('*')),
        'origin': origin, 'source': pathlib.Path('src/A.php').read_text(),
        'test': pathlib.Path('tests/ATest.php').read_text(), 'baseline': pathlib.Path('baseline.json').read_text(),
        'compiled': pathlib.Path('storage/views/compiled.php').exists(),
        'kept': pathlib.Path('storage/views/.gitignore').exists()}
with open(sys.argv[1], 'a') as log:
    log.write(json.dumps(said) + '\\n')
ledger.parent.mkdir(exist_ok=True)
ledger.write_text(head)
cache = pathlib.Path('.phpunit.cache/test-results')
cache.parent.mkdir(exist_ok=True)
cache.write_text(head)
pathlib.Path('baseline.json').write_text('raised')
pathlib.Path('storage/views/compiled.php').write_text(head)
"""


class Running(unittest.TestCase):
    def test_runs_each_scenario_on_the_project_s_commits_laid_over_the_prepared_one(self):
        with tempfile.TemporaryDirectory() as work:
            upstream, project, out = Path(work) / "upstream", Path(work) / "project", Path(work) / "out"
            upstream.mkdir()
            git(upstream, "init", "--quiet")
            git(upstream, "config", "uploadpack.allowAnySHA1InWant", "true")
            test = {"tests/ATest.php": "<?php $a = new A();"}
            base = commit(upstream, {"composer.json": "project", "src/A.php": "<?php $x = 2;", "src/Old.php": "o",
                                     **test}, "base")
            merged = commit(upstream, {"composer.json": "project", "src/A.php": "<?php $x = 3;", **test}, "merged")
            pinned = commit(upstream, {"composer.json": "project", "src/A.php": "<?php $x = 4;", **test}, "pinned")
            project.mkdir()
            git(project, "init", "--quiet")
            commit(project, {"composer.json": "bench", "src/A.php": "<?php $x = 4;", **test,
                             "baseline.json": "floor 0", "storage/views/.gitignore": "*\n!.gitignore\n",
                             ".gitignore": ".mutation-gate/\n"}, "prepared")
            log = Path(work) / "runs.jsonl"
            gate = Path(work) / "gate.py"
            gate.write_text(FAKE_GATE)
            described = {
                "project": "acme/money", "version": "1.0.0", "commit": pinned, "repository": f"file://{upstream}",
                "arms": {"gate": {"said": "the gate", "report": "gate",
                                  "command": [sys.executable, str(gate), str(log)]}},
                "scenarios": {
                    "generated": ["storage/views"],
                    "edit": [{"file": "src/A.php", "rename": "x", "to": "y"},
                             {"file": "tests/ATest.php", "rename": "a", "to": "b"}],
                    "replay": [{"pull": 7, "base": base, "merge": merged}],
                    "gate": ["gate"],
                },
            }
            bench_scenarios.run(described, project, out, rounds=2)
            runs = [json.loads(line) for line in log.read_text().splitlines()]
            edited = f"{pinned} with the scripted edit"
            self.assertEqual([(each["branch"], each["head"], each["options"][:1]) for each in runs], [
                ("main", pinned, ["--full"]),
                ("main", edited, ["--full"]),
                ("main", edited, ["--full"]),
                ("main", edited, ["--full"]),
                ("main", edited, ["--full"]),
                ("main", base, ["--full"]),
                ("pull-7", merged, [f"--changed-since={runs[6]['origin']}"]),
            ])
            self.assertEqual([each["ledger"] for each in runs], ["", pinned, pinned, edited, edited, "", base])
            self.assertEqual([(each["source"], each["test"]) for each in runs], [
                ("<?php $x = 4;", "<?php $a = new A();"),
                *[("<?php $y = 4;", "<?php $b = new A();")] * 4,
                ("<?php $x = 2;", "<?php $a = new A();"),
                ("<?php $x = 3;", "<?php $a = new A();"),
            ])
            self.assertEqual({each["owned"] for each in runs}, {"bench"})
            self.assertEqual([each["baseline"] for each in runs], ["floor 0"] * 7)
            self.assertEqual({(each["compiled"], each["kept"]) for each in runs}, {(False, True)})
            self.assertEqual(runs[5]["files"], ["A.php", "Old.php"])
            self.assertEqual(runs[6]["files"], ["A.php"])
            self.assertEqual(runs[5]["origin"], runs[6]["origin"])
            self.assertEqual(git(project, "log", "-1", "--format=%s", runs[6]["origin"]), base)
            self.assertEqual(git(project, "log", "-1", "--format=%s", "main"), "prepared")
            self.assertNotIn(".phpunit.cache", git(project, "log", "--all", "--format=", "--name-only"))
            self.assertEqual(sorted(path.name for path in out.glob("*.time")), [
                "gate-replay-7.time", "gate-unchanged-1.time", "gate-unchanged-2.time", "gate-warm-1.time",
                "gate-warm-2.time",
            ])

    def test_clears_the_ignored_files_under_each_generated_directory_and_nothing_beside_it_as_symfony_demo_needs(self):
        with tempfile.TemporaryDirectory() as work:
            project = Path(work)
            git(project, "init", "--quiet")
            commit(project, {".gitignore": "/var/\n", "src/A.php": "<?php",
                             "storage/views/.gitignore": "*\n!.gitignore\n"}, "prepared")
            built = ["var/cache/test/Container.php", "var/sass/app.output.css", "var/cachet/kept",
                     "storage/views/compiled.php"]
            for path in built:
                (project / path).parent.mkdir(parents=True, exist_ok=True)
                (project / path).write_text("built")
            bench_scenarios.cleared(project, ["var/cache", "storage/views"])
            self.assertEqual([path for path in built if (project / path).exists()],
                             ["var/sass/app.output.css", "var/cachet/kept"])
            self.assertTrue((project / "storage/views/.gitignore").exists())
            self.assertTrue((project / "src/A.php").exists())

    def test_runs_the_plain_arm_on_each_pull_request_s_change_from_its_base_with_its_own_diff_option(self):
        with tempfile.TemporaryDirectory() as work:
            upstream, plain, out = Path(work) / "upstream", Path(work) / "project-plain", Path(work) / "out"
            upstream.mkdir()
            git(upstream, "init", "--quiet")
            git(upstream, "config", "uploadpack.allowAnySHA1InWant", "true")
            base = commit(upstream, {"composer.json": "project", "src/A.php": "a1"}, "base")
            merged = commit(upstream, {"composer.json": "project", "src/A.php": "a2"}, "merged")
            plain.mkdir()
            git(plain, "init", "--quiet")
            commit(plain, {"composer.json": "bench", "src/A.php": "a2"}, "prepared")
            said = Path(work) / "said.json"
            told = "import json, sys, subprocess; json.dump({'argv': sys.argv[1:], 'head': subprocess.run(['git', 'log', '-1', '--format=%s'], capture_output=True, text=True).stdout.strip()}, open(sys.argv[1], 'w'))"
            described = {
                "repository": f"file://{upstream}",
                "arms": {"infection": {"said": "plain", "directory": "../project-plain",
                                       "command": [sys.executable, "-c", told, str(said)]}},
                "scenarios": {"diff": "infection", "replay": [{"pull": 9, "base": base, "merge": merged}]},
            }
            source = bench_scenarios.Source(described["repository"], Path(work) / "source")
            bench_scenarios.diffed(described, Path(work) / "project", out, source, {"composer.json"}, {}, None,
                                   described["scenarios"]["replay"])
            run = json.loads(said.read_text())
            self.assertEqual(run["head"], merged)
            self.assertEqual(run["argv"][1], "--git-diff-lines")
            self.assertEqual(git(plain, "log", "-1", "--format=%s", run["argv"][2].removeprefix("--git-diff-base=")), base)
            self.assertEqual(json.loads((out / "infection-replay-9.time").read_text())["exit"], 0)
            self.assertEqual((plain / "composer.json").read_text(), "bench")

    def test_reports_each_arm_s_scenarios_and_fails_where_a_timed_run_reached_no_verdict(self):
        with tempfile.TemporaryDirectory() as work:
            out, result = Path(work) / "out", Path(work) / "result.json"
            out.mkdir()
            for name, seconds, code in (("gate-warm-1", 10.0, 0), ("gate-warm-2", 12.0, 1),
                                        ("gate-unchanged-1", 2.0, 0), ("gate-replay-1", 3.0, 0),
                                        ("infection-replay-1", 30.0, 0)):
                (out / f"{name}.time").write_text(json.dumps({"seconds": seconds, "exit": code}))
            described = {**PROJECT, "arms": {"gate": {"said": "the gate"}, "infection": {"said": "plain"}},
                         "scenarios": {**PROJECT["scenarios"], "diff": "infection"}}
            self.assertEqual(bench_scenarios.report(described, out, result), 0)
            written = json.loads(result.read_text())
            self.assertEqual(written["arms"]["gate"]["warm"]["median"], 11.0)
            self.assertEqual(written["arms"]["gate"]["replay"]["judged"], 1)
            self.assertEqual(written["arms"]["infection"]["replay"]["median"], 30.0)
            (out / "gate-unchanged-2.time").write_text(json.dumps({"seconds": 1.0, "exit": "stopped"}))
            self.assertEqual(bench_scenarios.report(described, out, result), 1)

    def test_fails_where_a_round_of_an_unchanged_re_run_reused_less_than_the_first_as_jwt_s_did(self):
        with tempfile.TemporaryDirectory() as work:
            out, result = Path(work) / "out", Path(work) / "result.json"
            out.mkdir()
            for at, ran in ((1, 3), (2, 72), (3, 72)):
                (out / f"gate-unchanged-{at}.time").write_text(json.dumps({"seconds": 40.0, "exit": 0}))
                (out / f"gate-unchanged-{at}.json").write_text(
                    json.dumps({"run": {"units": {"run": ran, "proved": 72 - ran, "carried": 0}}}))
            described = {**PROJECT, "arms": {"gate": {"said": "the gate"}}}
            self.assertEqual(bench_scenarios.report(described, out, result), 1)
            self.assertEqual(json.loads(result.read_text())["arms"]["gate"]["unchanged"]["unitsRun"], [3, 72, 72])

    def test_passes_a_scenario_whose_later_rounds_reuse_as_much_as_the_first_or_more_or_wrote_no_report(self):
        for ran in ([1, 0, 0], [1, 1, 1], [0, None, 0], [None, 5, 5], []):
            with self.subTest(ran=ran), tempfile.TemporaryDirectory() as work:
                out, result = Path(work) / "out", Path(work) / "result.json"
                out.mkdir()
                for at, count in enumerate(ran, start=1):
                    (out / f"gate-warm-{at}.time").write_text(json.dumps({"seconds": 1.0, "exit": 0}))
                    report = {"run": {"units": {"run": count}}} if count is not None else {}
                    (out / f"gate-warm-{at}.json").write_text(json.dumps(report))
                described = {**PROJECT, "arms": {"gate": {"said": "the gate"}}}
                self.assertEqual(bench_scenarios.report(described, out, result), 0)


if __name__ == "__main__":
    unittest.main()
