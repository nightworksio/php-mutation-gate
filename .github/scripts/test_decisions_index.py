import contextlib
import io
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

    def test_renders_each_row_between_the_markers_with_its_status_and_what_changed_it(self):
        section = decisions_index.table(decisions_index.adrs(FILES))
        self.assertTrue(section.startswith(f"{decisions_index.START}\n\n| # | Decision | Status |\n|---|"))
        self.assertTrue(section.endswith(f"| [0005](0005-verdicts.md) | A verdict stands | Accepted |\n\n"
                                         f"{decisions_index.END}"))
        self.assertIn("| [0002](0002-floors.md) | Floors only rise | Accepted; \"a tree is judged whole\" superseded",
                      section)

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


class Files(unittest.TestCase):
    def test_writes_only_the_table_and_checks_it_says_what_to_run_where_it_is_stale(self):
        with tempfile.TemporaryDirectory() as work:
            directory = Path(work)
            for name, text in FILES.items():
                (directory / name).write_text(text)
            readme = f"# ADRs\n\n{decisions_index.START}\nold\n{decisions_index.END}\n\n## After\n"
            (directory / "README.md").write_text(readme)
            said = io.StringIO()
            with contextlib.redirect_stderr(said):
                self.assertEqual(decisions_index.main(["check", work]), 1)
            self.assertIn(decisions_index.WRITE, said.getvalue())
            self.assertEqual(decisions_index.main(["write", work]), 0)
            written = (directory / "README.md").read_text()
            self.assertTrue(written.startswith("# ADRs\n\n<!-- index:start -->\n\n| # |"))
            self.assertTrue(written.endswith("<!-- index:end -->\n\n## After\n"))
            self.assertEqual(decisions_index.main(["check", work]), 0)

    def test_fails_saying_why_where_an_adr_file_cannot_be_read_as_one(self):
        with tempfile.TemporaryDirectory() as work:
            (Path(work) / "0001-x.md").write_text("# ADR-0001: X\n")
            (Path(work) / "README.md").write_text(f"{decisions_index.START}\n{decisions_index.END}\n")
            said = io.StringIO()
            with contextlib.redirect_stderr(said):
                self.assertEqual(decisions_index.main(["check", work]), 1)
            self.assertIn("0001-x.md has no `**Status:**` line.", said.getvalue())

    def test_says_how_it_is_used_where_it_is_given_no_command(self):
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(decisions_index.main([]), 2)
            self.assertEqual(decisions_index.main(["render"]), 2)


class Committed(unittest.TestCase):
    def test_the_committed_index_is_the_one_the_adr_files_give(self):
        readme, section = decisions_index.read(ROOT / decisions_index.DECISIONS)
        self.assertEqual(decisions_index.section_of(readme), section,
                         f".docs/decisions/README.md's index is stale. Run: {decisions_index.WRITE}")


if __name__ == "__main__":
    unittest.main()
