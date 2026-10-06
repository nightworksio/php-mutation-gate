"""The deciding half of warm_measure.py: python3 -m unittest discover .github/scripts"""
import unittest

import warm_measure


def report(*mutants: tuple[str, str, float]) -> dict:
    return {
        "mutants": [
            {"id": mutant_id, "file": "src/Money.php", "line": 7, "status": status, "seconds": seconds}
            for mutant_id, status, seconds in mutants
        ]
    }


class Disagreements(unittest.TestCase):
    def test_none_where_every_mutant_has_one_verdict(self):
        fresh = report(("a", "killed", 0.5), ("b", "survived", 0.4))
        fork = report(("b", "survived", 0.2), ("a", "killed", 0.3))
        self.assertEqual(warm_measure.disagreements(fresh, fork), [])

    def test_names_a_mutant_whose_verdict_differs_where_it_is(self):
        found = warm_measure.disagreements(report(("a", "survived", 0.5)), report(("a", "killed", 0.3)))
        self.assertEqual(
            found,
            [{"path": "src/Money.php", "line": 7, "rule": "verdict", "detail": "a: survived fresh, killed forked"}],
        )

    def test_names_a_mutant_one_run_lacks(self):
        found = warm_measure.disagreements(report(("a", "killed", 0.5)), report())
        self.assertEqual([finding["detail"] for finding in found], ["a: killed fresh, absent forked"])


class Table(unittest.TestCase):
    def test_compares_wall_time_and_the_time_the_mutants_took_leaving_out_those_no_run_judged(self):
        fresh = report(("a", "killed", 0.5), ("b", "survived", 0.7), ("c", "uncovered", 0.0))
        fork = report(("a", "killed", 0.25), ("b", "survived", 0.35), ("c", "uncovered", 0.0))
        self.assertEqual(
            warm_measure.table(fresh, 100.0, fork, 80.0),
            "| | fresh | fork |\n"
            "|---|---:|---:|\n"
            "| wall time | 100.0 s | 80.0 s |\n"
            "| mutants run | 2 | 2 |\n"
            "| their summed time | 1.2 s | 0.6 s |\n"
            "| median mutant | 0.600 s | 0.300 s |\n"
            "\nThe forked run took 80% of the fresh run's wall time.\n",
        )

    def test_says_nothing_of_a_median_or_a_share_it_cannot_take(self):
        text = warm_measure.table(report(), 0.0, report(), 0.0)
        self.assertIn("| median mutant | - | - |", text)
        self.assertIn("took - of", text)


if __name__ == "__main__":
    unittest.main()
