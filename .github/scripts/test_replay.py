"""The deciding half of replay.py: python3 -m unittest discover .github/scripts"""
import contextlib
import io
import unittest

import replay

LOG = "\n".join([
    f"{'a' * 40}\tfeat(reach): a fixture reaches its tests (#538)",
    f"{'b' * 40}\tMerge pull request 12 from a fork",
    f"{'c' * 40}\tfix(plan): the plan says why (#532)",
    f"{'d' * 40}\tdocs: the README (#530)",
])


def plan(*reasons: str, widening: list[int] | None = None, units: list[str] | None = None) -> dict:
    return {"reach": list(reasons), "widening": widening or [],
            "shards": [{"units": [{"path": path} for path in (units or [])]}]}


class Merged(unittest.TestCase):
    def test_reads_each_squashed_pull_request_s_number_and_commit_newest_first_up_to_the_count(self):
        self.assertEqual(replay.merged(LOG, 2), [(538, "a" * 40), (532, "c" * 40)])
        self.assertEqual(replay.merged(LOG, 9), [(538, "a" * 40), (532, "c" * 40), (530, "d" * 40)])
        self.assertEqual(replay.merged("", 3), [])


class Reached(unittest.TestCase):
    def test_takes_every_unit_of_every_shard_by_its_path(self):
        plans = {"shards": [{"units": [{"path": "src/A.php"}, {"path": "src/B.php", "group": "g"}]},
                            {"units": [{"path": "src/A.php"}]}]}
        self.assertEqual(replay.reached(plans), {"src/A.php", "src/B.php"})
        self.assertEqual(replay.reached({}), set())

    def test_takes_the_widening_reasons_in_their_order_and_ignores_a_place_the_reasons_do_not_hold(self):
        self.assertEqual(replay.widening(plan("a", "b", "c", widening=[2, 0, 7])), ["c", "a"])


class Judge(unittest.TestCase):
    def test_passes_a_widening_reason_that_quotes_a_changed_path(self):
        widened = plan("`composer.json` decides how the gate runs, so every unit is reached.", widening=[0])
        self.assertEqual(replay.judge(538, ["composer.json", "src/A.php"], widened, {"src/A.php"}), [])

    def test_finds_a_widening_reason_that_quotes_no_changed_path_or_none(self):
        widened = plan("`phpunit.xml` decides how the gate runs, so every unit is reached.",
                       "git cannot tell what changed. So every unit is reached.", "`src/A.php` changed.", widening=[0, 1])
        found = replay.judge(538, ["src/A.php"], widened, set())
        self.assertEqual([finding["rule"] for finding in found], ["unjustified-widening", "unjustified-widening"])
        self.assertTrue(found[0]["detail"].startswith("#538: `phpunit.xml`"))

    def test_finds_documentation_alone_that_reached_a_unit_and_passes_one_that_reached_none(self):
        found = replay.judge(530, ["README.md", ".docs/guide/a.md"], plan(), {"src/B.php", "src/A.php"})
        self.assertEqual(found, [{"path": "", "line": 0, "rule": "documentation-reaches",
                                  "detail": "#530 changed only documentation and reached 2 units, src/A.php, src/B.php first."}])
        self.assertEqual(replay.judge(530, ["README.md"], plan(), set()), [])
        self.assertEqual(replay.judge(531, ["README.md", "src/A.php"], plan(), {"src/A.php"}), [])

    def test_takes_documentation_for_markdown_the_docs_and_the_site_alone(self):
        self.assertTrue(all(replay.is_documentation(path) for path in ("CHANGELOG.md", ".docs/x.yml", ".site/a.mjs", "LICENSE")))
        self.assertFalse(any(replay.is_documentation(path) for path in (".github/workflows/ci.yml", "tests/Fixtures/a.json")))


class Table(unittest.TestCase):
    def test_writes_a_row_for_each_replay_and_a_line_for_each_skipped_one(self):
        rows = [{"number": 538, "paths": 3, "units": 12, "shards": 2, "widening": []},
                {"number": 532, "paths": 1, "units": 2239, "shards": 20, "widening": ["`a` decides.", "`b` decides."]}]
        self.assertEqual(replay.table(rows, [{"number": 529, "why": "reach.fixtures: unknown key"}]), "\n".join([
            "| Pull request | Paths | Units reached | Shards | Widened by |",
            "|---|---:|---:|---:|---|",
            "| #538 | 3 | 12 | 2 | nothing |",
            "| #532 | 1 | 2239 | 20 | `a` decides.<br>`b` decides. |",
            "",
            "- #529 could not be planned on this checkout: reach.fixtures: unknown key",
        ]) + "\n")
        self.assertTrue(replay.table([], []).endswith("|---|---:|---:|---:|---|\n"))


class Usage(unittest.TestCase):
    def test_says_how_it_is_used_where_its_arguments_are_not_its_own(self):
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(replay.main([]), 2)
            self.assertEqual(replay.main(["ten", "--map", "m", "--findings", "f", "--table", "t"]), 2)
            self.assertEqual(replay.main(["3", "--map", "m", "--table", "t", "--findings", "f"]), 2)


if __name__ == "__main__":
    unittest.main()
