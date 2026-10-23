"""The benchmark's published numbers: python3 -m unittest discover .github/scripts"""
import json
import tempfile
import unittest
from pathlib import Path

import bench_publish

ROOT = Path(__file__).resolve().parents[2]


def spread(median: float, low: float, high: float) -> dict:
    return {"median": median, "min": low, "max": high, "rounds": [low, median, high]}


def totals(generated: int, escaped: int) -> dict:
    return {"total": {"generated": generated, "killed": generated - escaped, "escaped": escaped, "timed out": 0,
                      "uncovered": 0, "other": 0}}


PINNED = {"project": "acme/money", "version": "1.0.0", "commit": "0123456789abcdef",
          "machine": {"cpu": "AMD EPYC 7763"}}

RESULTS = {
    "money": {**PINNED, "arms": {"infection": {"wall": spread(300, 290, 320), "files": totals(40, 2)}}},
    "money-sharded": {**PINNED, "arms": {"gate-sharded": {
        "shards": 3, "wallWithSetup": spread(120, 120, 120), "runnerWithSetup": spread(400, 390, 410),
        "files": totals(40, 2)}}},
    "money-scenarios": {**PINNED, "arms": {"gate-infection": {
        "warm": spread(30, 29, 31), "unchanged": spread(5, 5, 5),
        "replay": {"pulls": 15, "judged": 14, "median": 20, "p90": 75, "otherwise": [2]}}}},
}


class Combined(unittest.TestCase):
    def test_keys_each_result_by_its_project_and_what_measured_it(self):
        combined = bench_publish.combined(RESULTS, "1.0.0", "https://run", "2026-10-23T00:00:00Z")
        self.assertEqual(list(combined["projects"]), ["money"])
        self.assertEqual(sorted(combined["projects"]["money"]), ["cold", "scenarios", "sharded"])
        self.assertEqual((combined["version"], combined["run"]), ("1.0.0", "https://run"))


class Duration(unittest.TestCase):
    def test_spells_seconds_minutes_and_hours(self):
        self.assertEqual([bench_publish.duration(each) for each in (48.4, 185, 3725)], ["48s", "3m 05s", "1h 02m"])


class Table(unittest.TestCase):
    def test_lists_each_arm_of_each_scenario_linked_to_the_run_and_the_raw_file(self):
        combined = bench_publish.combined(RESULTS, "1.0.0", "https://run", "2026-10-23T00:00:00Z")
        text = bench_publish.table(combined, "bench/results/1.0.0.json")
        self.assertIn("[this run of `bench.yml`](https://run)", text)
        self.assertIn("[bench/results/1.0.0.json](bench/results/1.0.0.json)", text)
        self.assertIn("(AMD EPYC 7763)", text)
        self.assertIn("### acme/money 1.0.0 at `0123456789ab`", text)
        self.assertIn("| cold full | infection | 5m 00s (4m 50s–5m 20s) | - | 40, 2 escaped |", text)
        self.assertIn("| cold full | gate-sharded, 3 shards | 2m 00s | 6m 40s (6m 30s–6m 50s) | 40, 2 escaped |", text)
        self.assertIn("| warm full | gate-infection | 30s (29s–31s) | - | - |", text)
        self.assertIn("| unchanged re-run (trivial) | gate-infection | 5s | - | - |", text)
        self.assertIn("| PR replay, 14 of 15 judged | gate-infection | 20s median, 1m 15s p90 | - | - |", text)

    def test_says_none_is_committed_where_no_result_is(self):
        self.assertEqual(bench_publish.table(None, ""),
                         f"{bench_publish.START}\n\n{bench_publish.NONE}\n\n{bench_publish.END}")


class Readme(unittest.TestCase):
    def test_replaces_only_the_table(self):
        readme = f"# Title\n\n{bench_publish.START}\nold\n{bench_publish.END}\n\n## Next\n"
        self.assertEqual(bench_publish.rendered(readme, "new"), "# Title\n\nnew\n\n## Next\n")

    def test_takes_the_newest_result_by_the_date_it_holds(self):
        with tempfile.TemporaryDirectory() as work:
            for name, date in (("b", "2026-01-01T00:00:00Z"), ("a", "2026-02-01T00:00:00Z")):
                (Path(work) / f"{name}.json").write_text(json.dumps({"date": date, "name": name}))
            found, path = bench_publish.newest(Path(work))
            self.assertEqual((found["name"] if found else "", path), ("a", "bench/results/a.json"))

    def test_the_readme_s_table_is_the_one_its_newest_result_renders(self):
        found, path = bench_publish.newest(ROOT / "bench" / "results")
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertEqual(bench_publish.section_of(readme), bench_publish.table(found, path),
                         "The README's benchmark table differs: run "
                         "python3 .github/scripts/bench_publish.py readme bench/results README.md")


if __name__ == "__main__":
    unittest.main()
