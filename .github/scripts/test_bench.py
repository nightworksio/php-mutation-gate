"""The deciding half of bench.py: python3 -m unittest discover .github/scripts"""
import json
import sys
import tempfile
import time
import unittest
from pathlib import Path

import bench

ROOT = "/work/jwt"

DIFF = "--- Original\n+++ New\n@@ @@\n-        return $a + $b;\n+        return $a - $b;\n"


def infection_entry(line: int, mutator: str = "Plus", diff: str = DIFF) -> dict:
    return {
        "mutator": {"mutatorName": mutator, "originalFilePath": f"{ROOT}/src/Money.php", "originalStartLine": line},
        "diff": diff,
    }


def gate_mutant(line: int, status: str, mutator: str = "Plus", judgement: str = "") -> dict:
    mutant = {"file": "src/Money.php", "line": line, "mutator": mutator, "diff": DIFF, "status": status}
    return {**mutant, "judgement": judgement} if judgement else mutant


class Outcome(unittest.TestCase):
    def test_reads_each_infection_list_as_the_outcome_it_holds(self):
        report = {"killed": [infection_entry(3)], "escaped": [infection_entry(4)], "timeouted": [infection_entry(5)]}
        found = bench.outcome(report, "infection", ROOT)
        self.assertEqual(
            [(each["file"], each["line"], each["status"], each["outcome"]) for each in found],
            [("src/Money.php", 3, "killed", "killed"), ("src/Money.php", 4, "escaped", "escaped"),
             ("src/Money.php", 5, "timeouted", "timed out")],
        )

    def test_reads_the_gate_s_statuses_a_kill_by_an_analyser_a_kill_and_an_unknown_one_other(self):
        report = {"mutants": [gate_mutant(3, "killed-by-static-analysis"), gate_mutant(4, "survived"),
                              gate_mutant(5, "unjudged")]}
        self.assertEqual([each["outcome"] for each in bench.outcome(report, "gate", ROOT)],
                         ["killed", "escaped", "other"])

    def test_names_a_mutator_by_its_short_name_and_a_diff_by_the_lines_it_changes(self):
        found = bench.outcome({"mutants": [gate_mutant(3, "killed", mutator="Infection\\Mutator\\Plus")]}, "gate", ROOT)
        self.assertEqual((found[0]["mutator"], found[0]["diff"]), ("Plus", ("-return $a + $b;", "+return $a - $b;")))


class Tally(unittest.TestCase):
    def test_counts_each_file_and_the_whole_run(self):
        found = bench.outcome({"killed": [infection_entry(3)], "escaped": [infection_entry(4)]}, "infection", ROOT)
        counts = bench.tally(found)
        self.assertEqual(counts["src/Money.php"]["generated"], 2)
        self.assertEqual((counts["total"]["killed"], counts["total"]["escaped"]), (1, 1))


class Pairs(unittest.TestCase):
    def test_none_where_both_arms_judge_every_mutant_alike(self):
        plain = bench.outcome({"killed": [infection_entry(3)]}, "infection", ROOT)
        gate = bench.outcome({"mutants": [gate_mutant(3, "killed")]}, "gate", ROOT)
        self.assertEqual(bench.pairs(plain, gate), [])

    def test_names_a_mutant_one_arm_kills_and_the_other_lets_escape_unexplained(self):
        plain = bench.outcome({"escaped": [infection_entry(3)]}, "infection", ROOT)
        gate = bench.outcome({"mutants": [gate_mutant(3, "killed")]}, "gate", ROOT)
        self.assertEqual(bench.pairs(plain, gate), [{
            "file": "src/Money.php", "line": 3, "mutator": "Plus", "plain": "escaped", "gate": "killed",
            "explained": False,
        }])

    def test_explains_a_kill_by_an_analyser_and_a_survivor_confirmed_flaky(self):
        plain = bench.outcome({"escaped": [infection_entry(3)], "killed": [infection_entry(4)]}, "infection", ROOT)
        gate = bench.outcome(
            {"mutants": [gate_mutant(3, "killed-by-static-analysis"), gate_mutant(4, "survived", judgement="flaky")]},
            "gate",
            ROOT,
        )
        self.assertEqual([each["explained"] for each in bench.pairs(plain, gate)], [True, True])

    def test_names_a_mutant_one_arm_lacks(self):
        plain = bench.outcome({"killed": [infection_entry(3), infection_entry(3)]}, "infection", ROOT)
        gate = bench.outcome({"mutants": [gate_mutant(3, "killed")]}, "gate", ROOT)
        self.assertEqual([(each["plain"], each["gate"]) for each in bench.pairs(plain, gate)], [("killed", "absent")])


PEST_PRINTED = """
  ..x.-t

  ------------------------------------------------------------------------
  UNTESTED  app/Actions/Follow.php  > Line 12: RemoveMethodCall - ID: abc1
  @@ @@
-        $user->notify();
+
  ------------------------------------------------------------------------
  UNCOVERED  app/Actions/Unfollow.php  > Line 7: AlwaysReturnNull - ID: abc2
  @@ @@
-        return $user;
+        return null;

  Mutations: 1 untested, 1 uncovered, 1 timeout, 3 tested
  Score:     60.00%
"""


class Pest(unittest.TestCase):
    def test_reads_each_mutant_plain_pest_names_with_its_diff_and_its_counts(self):
        found, counts = bench.pest(PEST_PRINTED)
        self.assertEqual(
            [(each["file"], each["line"], each["mutator"], each["outcome"], each["diff"]) for each in found],
            [("app/Actions/Follow.php", 12, "RemoveMethodCall", "escaped", ("-$user->notify();", "+")),
             ("app/Actions/Unfollow.php", 7, "AlwaysReturnNull", "uncovered",
              ("-return $user;", "+return null;"))],
        )
        self.assertEqual(counts, {"killed": 3, "escaped": 1, "timed out": 1, "uncovered": 1, "other": 0})

    def test_takes_the_counts_for_the_whole_run(self):
        found, counts = bench.pest(PEST_PRINTED)
        self.assertEqual(bench.tally(found, counts)["total"]["generated"], 6)

    def test_matches_the_gate_s_escaped_mutants_with_those_pest_names_and_takes_the_rest_as_killed(self):
        found, _ = bench.pest(PEST_PRINTED)
        gate = [
            {"file": "app/Actions/Follow.php", "line": 12, "mutator": "RemoveMethodCall", "diff": ("-x",),
             "status": "survived", "outcome": "escaped"},
            {"file": "app/Actions/Unfollow.php", "line": 7, "mutator": "AlwaysReturnNull", "diff": (),
             "status": "uncovered", "outcome": "uncovered"},
            {"file": "app/Actions/Block.php", "line": 3, "mutator": "RemoveMethodCall", "diff": (),
             "status": "survived", "outcome": "escaped"},
            {"file": "app/Actions/Block.php", "line": 4, "mutator": "RemoveMethodCall", "diff": (),
             "status": "killed", "outcome": "killed"},
        ]
        self.assertEqual(
            [(each["file"], each["plain"], each["gate"]) for each in bench.pairs(found, gate, named_only=True)],
            [("app/Actions/Block.php", "killed or timed out", "survived")],
        )


class Spread(unittest.TestCase):
    def test_gives_the_median_and_range_of_the_rounds(self):
        self.assertEqual(
            bench.spread([3.0, 1.0, 2.0]),
            {"median": 2.0, "min": 1.0, "max": 3.0, "rounds": [3.0, 1.0, 2.0]},
        )


class Summary(unittest.TestCase):
    def test_tabulates_each_arm_and_names_each_difference(self):
        project = {"project": "lcobucci/jwt", "version": "5.6.0", "commit": "bb3e9f21e4196e8a", "same": ["a", "b"]}
        counts = bench.tally(bench.outcome({"killed": [infection_entry(3)]}, "infection", ROOT))
        arms = {name: {"wall": bench.spread([2.0]), "exits": [0], "files": counts} for name in ("a", "b")}
        machine = {"cpu": "AMD EPYC", "cores": 4, "memory": "15.6 GiB", "php": "8.5.0"}
        differences = [{"file": "src/Money.php", "line": 3, "mutator": "Plus", "plain": "killed", "gate": "absent",
                        "explained": False}]
        text = bench.summary(project, arms, differences, machine)
        self.assertIn("| a | 2.0 s (2.0–2.0) | 1 | 1 | 0 | 0 | 0 | 0 | 0 |", text)
        self.assertIn("| src/Money.php | 1 / 1 / 0 / 0 / 0 / 0 | 1 / 1 / 0 / 0 / 0 / 0 |", text)
        self.assertIn("Mutants they judge otherwise or one lacks: 1, of which unexplained: 1.", text)
        self.assertIn("| src/Money.php | 3 | Plus | killed | absent | no |", text)


class Run(unittest.TestCase):
    def test_says_a_round_ended_by_its_exit_code_or_as_stopped(self):
        self.assertEqual(bench.ended(0, stopped=False), 0)
        self.assertEqual(bench.ended(2, stopped=False), 2)
        self.assertEqual(bench.ended(-9, stopped=True), "stopped")
        self.assertEqual(bench.ended(None, stopped=False), "stopped")

    def test_stops_an_arm_that_outruns_its_time_with_what_it_started_and_keeps_what_it_left(self):
        with tempfile.TemporaryDirectory() as work:
            place, out = Path(work) / "project", Path(work) / "out"
            place.mkdir()
            started = (
                "import pathlib, subprocess, sys, time;"
                "pathlib.Path('.gate').mkdir();"
                "pathlib.Path('.gate/plan.json').write_text('{}');"
                "subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)']);"
                "time.sleep(30)"
            )
            project = {"arms": {"slow": {"command": [sys.executable, "-c", started], "keep": [".gate", "gone"]}}}
            began = time.monotonic()
            bench.run(project, place, out, rounds=1, arm_seconds=1.0)
            self.assertLess(time.monotonic() - began, 20)
            self.assertEqual(json.loads((out / "slow-1.time").read_text())["exit"], "stopped")
            self.assertEqual((out / "slow-1.kept" / ".gate" / "plan.json").read_text(), "{}")
            self.assertFalse((out / "slow-1.kept" / "gone").exists())

    def test_records_the_exit_of_an_arm_that_ends_in_time(self):
        with tempfile.TemporaryDirectory() as work:
            place, out = Path(work) / "project", Path(work) / "out"
            place.mkdir()
            project = {"arms": {"quick": {"command": [sys.executable, "-c", "import sys; sys.exit(3)"]}}}
            bench.run(project, place, out, rounds=1, arm_seconds=30.0)
            self.assertEqual(json.loads((out / "quick-1.time").read_text())["exit"], 3)


if __name__ == "__main__":
    unittest.main()
