import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

import decisions_index

ROOT = Path(__file__).resolve().parents[2]


def adr(number: str, title: str, *changes: str, status: str = "Accepted") -> str:
    lines = [f"# ADR-{number}: {title}", "", f"**Status:** {status}", "**Date:** 2026-11-01", *changes, "",
             "## Context"]
    return "\n".join(lines) + "\n"


FILES = {
    "0001-a-core.md": adr("0001", "A core"),
    "0002-floors.md": adr("0002", "Floors only rise"),
    "0003-sampling.md": adr("0003", "A huge repository can be sampled",
                            "**Supersedes:** [0001](0001-a-core.md): its list of ports",
                            "**Supersedes:** [0002](0002-floors.md): \"a tree is judged whole\", for sampled runs"),
    "0004-runners.md": adr("0004", "Native runners",
                           "**Supersedes:** [0001](0001-a-core.md): its list of ports",
                           "**Supersedes:** [0001](0001-a-core.md): its gate that never mutates, for native runners"),
    "0005-verdicts.md": adr("0005", "A verdict stands", "**Amends:** [0002](0002-floors.md): its lowering"),
    "README.md": "# ADRs\n",
    "notes.txt": "not an ADR\n",
}


class Rows(unittest.TestCase):
    def test_takes_each_adr_s_number_file_title_and_status_from_its_own_file(self):
        found = decisions_index.adrs({"0007-proofs.md": adr("0007", "Proofs are keyed", status="Proposed")})
        self.assertEqual(found, [{"number": "0007", "file": "0007-proofs.md", "title": "Proofs are keyed",
                                  "status": "Proposed", "changes": []}])

    def test_says_what_later_adrs_change_grouped_by_what_and_verb_its_verb_left_out_after_the_same(self):
        found = decisions_index.adrs(FILES)
        self.assertEqual(decisions_index.changes(found, "0001"),
                         "its list of ports superseded by [0003](0003-sampling.md) and [0004](0004-runners.md), "
                         "and its gate that never mutates by [0004](0004-runners.md) for native runners")
        self.assertEqual(decisions_index.changes(found, "0002"),
                         "\"a tree is judged whole\" superseded by [0003](0003-sampling.md) for sampled runs, "
                         "and its lowering amended by [0005](0005-verdicts.md)")
        self.assertEqual(decisions_index.changes(found, "0005"), "")

    def test_joins_three_adrs_that_change_one_thing_alike_with_commas_and_a_last_and(self):
        narrowing = "**Narrows:** [0001](0001-a-core.md): its lock file"
        files = {"0001-a-core.md": adr("0001", "A core"),
                 **{f"000{n}-x.md": adr(f"000{n}", "X", narrowing) for n in (2, 3, 4)}}
        self.assertEqual(decisions_index.changes(decisions_index.adrs(files), "0001"),
                         "its lock file narrowed by [0002](0002-x.md), [0003](0003-x.md) and [0004](0004-x.md)")

    def test_gives_each_adr_a_line_names_after_a_semicolon_its_own_change(self):
        files = {"0001-a-core.md": adr("0001", "A core"), "0002-floors.md": adr("0002", "Floors only rise"),
                 "0003-x.md": adr("0003", "X", "**Amends:** [0001](0001-a-core.md): its ports; "
                                               "[0002](0002-floors.md): its lowering")}
        found = decisions_index.adrs(files)
        self.assertEqual(decisions_index.changes(found, "0001"), "its ports amended by [0003](0003-x.md)")
        self.assertEqual(decisions_index.changes(found, "0002"), "its lowering amended by [0003](0003-x.md)")

    def test_gives_each_row_its_number_file_title_and_status_with_what_changed_it(self):
        found = decisions_index.rows(decisions_index.adrs(FILES))
        self.assertEqual([row["number"] for row in found], ["0001", "0002", "0003", "0004", "0005"])
        self.assertEqual(found[4], {"number": "0005", "file": "0005-verdicts.md", "title": "A verdict stands",
                                    "status": "Accepted"})
        self.assertEqual(found[1]["status"], "Accepted; \"a tree is judged whole\" superseded by "
                                             "[0003](0003-sampling.md) for sampled runs, "
                                             "and its lowering amended by [0005](0005-verdicts.md)")

    def test_refuses_a_verb_it_does_not_know_a_link_to_another_file_and_a_file_without_its_heading(self):
        refused = {
            "a verb": {**FILES, "0006-x.md": adr("0006", "X", "**Replaces:** [0001](0001-a-core.md): it")},
            "a link": {**FILES, "0006-x.md": adr("0006", "X", "**Amends:** [0001](0002-floors.md): it")},
            "a heading": {**FILES, "0006-x.md": "# ADR-0007: X\n\n**Status:** Accepted\n"},
            "a status": {**FILES, "0006-x.md": "# ADR-0006: X\n"},
        }
        for case, files in refused.items():
            with self.subTest(case), self.assertRaises(ValueError):
                decisions_index.adrs(files)


EMPTY = f"# ADRs\n\n{decisions_index.START}\n{decisions_index.END}\n\n## After\n"


@contextlib.contextmanager
def directory(files: dict[str, str]):
    with tempfile.TemporaryDirectory() as work:
        for name, text in files.items():
            (Path(work) / name).write_text(text)
        yield work


class Problems(unittest.TestCase):
    def test_finds_nothing_in_decisions_whose_readme_holds_only_its_markers(self):
        self.assertEqual(decisions_index.problems({**FILES, "README.md": EMPTY}), [])

    def test_says_which_files_take_one_number(self):
        files = {**FILES, "README.md": EMPTY, "0005-a-later-one.md": adr("0005", "A later one")}
        self.assertEqual(decisions_index.problems(files),
                         ["ADR-0005 is taken by 0005-a-later-one.md and 0005-verdicts.md; "
                          "give the newer one the next free number."])

    def test_refuses_an_index_committed_between_the_markers_and_a_readme_without_them(self):
        committed = f"{decisions_index.START}\n| # | Decision |\n{decisions_index.END}\n"
        self.assertEqual(decisions_index.problems({**FILES, "README.md": committed}),
                         ["README.md holds an index between its markers; the site builds it, so leave nothing there."])
        self.assertEqual(decisions_index.problems({**FILES, "README.md": "# ADRs\n"}),
                         [f"README.md has no {decisions_index.START} and {decisions_index.END} markers "
                          "for the site to build the index between."])

    def test_says_why_an_adr_file_cannot_be_read_as_one(self):
        self.assertEqual(decisions_index.problems({"0001-x.md": "# ADR-0001: X\n", "README.md": EMPTY}),
                         ["0001-x.md has no `**Status:**` line."])


class Files(unittest.TestCase):
    def test_validates_a_directory_and_prints_its_rows_as_json(self):
        with directory({**FILES, "README.md": EMPTY}) as work:
            self.assertEqual(decisions_index.main(["validate", work]), 0)
            printed = io.StringIO()
            with contextlib.redirect_stdout(printed):
                self.assertEqual(decisions_index.main(["rows", work]), 0)
        self.assertEqual(json.loads(printed.getvalue()), decisions_index.rows(decisions_index.adrs(FILES)))

    def test_fails_saying_why_and_prints_no_rows_where_a_problem_stands(self):
        with directory({**FILES, "README.md": "# ADRs\n"}) as work:
            for command in ("validate", "rows"):
                said, printed = io.StringIO(), io.StringIO()
                with contextlib.redirect_stderr(said), contextlib.redirect_stdout(printed):
                    self.assertEqual(decisions_index.main([command, work]), 1, command)
                self.assertIn("README.md has no", said.getvalue(), command)
                self.assertEqual(printed.getvalue(), "", command)

    def test_says_how_it_is_used_where_it_is_given_no_command(self):
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(decisions_index.main([]), 2)
            self.assertEqual(decisions_index.main(["write"]), 2)
            self.assertEqual(decisions_index.main(["validate", "a", "b"]), 2)


class TheRepository(unittest.TestCase):
    def test_s_decisions_validate(self):
        files = decisions_index.read(ROOT / decisions_index.DECISIONS)
        self.assertEqual(decisions_index.problems(files), [], f"Run: {decisions_index.VALIDATE}")


if __name__ == "__main__":
    unittest.main()
