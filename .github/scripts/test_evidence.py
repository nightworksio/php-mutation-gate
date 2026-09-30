"""The deciding half of evidence.py: python3 -m unittest discover .github/scripts"""
import json
import unittest
from pathlib import Path

import evidence

FIXTURES = Path(__file__).resolve().parents[2] / "tests" / "Fixtures" / "Evidence"
ROOT = "/home/runner/work/php-mutation-gate/php-mutation-gate"


def read(tool: str, fixture: str) -> list[tuple]:
    found = evidence.READERS[tool]((FIXTURES / fixture).read_text(encoding="utf-8"), ROOT)
    return [(item["path"], item["line"], item["rule"]) for item in found]


class Readers(unittest.TestCase):
    def test_phpstan_names_the_package_rule_a_message_closes_on_and_the_identifier_otherwise(self):
        self.assertEqual(
            read("phpstan", "phpstan.json"),
            [
                ("src/Core/Planted/Bad.php", 5, "ergebnis.final"),
                ("src/Core/Planted/Bad.php", 6, "missingType.return"),
                ("src/Core/Planted/Bad.php", 6, "missingType.parameter"),
                ("src/Core/Planted/Bad.php", 6, "shipmonk.missingNativeReturnTypehint"),
                ("src/Core/Planted/Bad.php", 7, "L3"),
                ("src/Core/Planted/Bad.php", 7, "H5"),
                ("src/Core/Planted/Bad.php", 7, "argument.type"),
                ("src/Core/Planted/Bad.php", 7, "C5"),
            ],
        )

    def test_phpstan_names_the_rule_a_message_leads_with_where_it_cites_several(self):
        text = json.dumps({"files": {"src/A.php": {"messages": [{"message": "C6 \u2014 this catch swallows it (C6, C1).", "line": 4, "identifier": "mutationGate.broadCatch"}]}}})

        self.assertEqual([f["rule"] for f in evidence.phpstan(text, ROOT)], ["C6"])

    def test_phpstan_counts_an_error_outside_any_file(self):
        found = evidence.phpstan('{"files": {}, "errors": ["Internal error"]}', ROOT)

        self.assertEqual([(f["path"], f["line"], f["rule"]) for f in found], [("", 0, "phpstan")])

    def test_pint_names_each_fixer_a_file_needs_with_no_line(self):
        found = read("pint", "pint.json")

        self.assertEqual(len(found), 11)
        self.assertEqual(found[0], ("src/Core/Planted/Bad.php", 0, "final_class"))

    def test_rector_names_each_rule_at_the_line_it_changed(self):
        self.assertEqual(
            read("rector", "rector.json"),
            [
                ("src/Core/Planted/Bad.php", 6, "Rector\\TypeDeclaration\\Rector\\ClassMethod\\ReturnUnionTypeRector"),
                ("src/Core/Planted/Bad.php", 7, "Rector\\EarlyReturn\\Rector\\If_\\RemoveAlwaysElseRector"),
                ("src/Core/Planted/Bad.php", 3, "Rector\\PostRector\\Rector\\UnusedImportRemovingPostRector"),
            ],
        )

    def test_rector_falls_back_on_the_rules_it_applied_where_it_gives_no_lines(self):
        text = json.dumps({"file_diffs": [{"file": "src/A.php", "applied_rectors": ["Rector\\A"]}]})

        self.assertEqual([(f["path"], f["line"], f["rule"]) for f in evidence.rector(text, ROOT)], [("src/A.php", 0, "Rector\\A")])

    def test_junit_names_each_failed_test_where_it_broke_and_a_rule_its_message_names(self):
        self.assertEqual(
            read("pest", "pest.xml"),
            [
                ("tests/Unit/PlantedTest.php", 6, "failure"),
                ("tests/Unit/PlantedTest.php", 10, "G2"),
                ("tests/Unit/PlantedTest.php", 18, "error"),
            ],
        )

    def test_junit_takes_no_line_from_a_file_other_than_the_tests_own(self):
        text = (
            '<testsuites><testcase name="it" file="tests/ATest.php::it">'
            "<failure>boom\nat src/Money.php:4</failure></testcase></testsuites>"
        )

        self.assertEqual([(f["path"], f["line"]) for f in evidence.junit(text, ROOT)], [("tests/ATest.php", 0)])

    def test_an_xml_report_that_declares_a_document_type_is_refused(self):
        text = '<!DOCTYPE x [<!ENTITY a "b">]><testsuites/>'

        with self.assertRaises(ValueError):
            evidence.junit(text, ROOT)

    def test_clover_names_every_statement_no_test_ran(self):
        self.assertEqual(
            read("coverage", "coverage.xml"),
            [("src/Core/Cost/Shares.php", 43, "uncovered"), ("src/Core/Cost/Shares.php", 44, "uncovered")],
        )

    def test_normalize_names_the_manifest_where_it_printed_a_diff_and_nothing_otherwise(self):
        self.assertEqual(read("composer-normalize", "composer-normalize.txt"), [("composer.json", 0, "composer-normalize")])
        self.assertEqual(evidence.normalize("composer.json is already normalized.", ROOT), [])

    def test_audit_names_each_advisory_and_each_abandoned_package(self):
        self.assertEqual(read("composer-audit", "composer-audit.json"), [("composer.lock", 0, "advisory"), ("composer.lock", 0, "abandoned")])
        self.assertEqual(evidence.audit('{"advisories": [], "abandoned": []}', ROOT), [])

    def test_the_dependency_analyser_names_each_kind_of_problem_at_its_first_use(self):
        self.assertEqual(
            read("dependency-analyser", "dependency-analyser.xml"),
            [("src/Core/Planted/Uses.php", 11, "unknown-classes"), ("src/Core/Planted/Uses.php", 11, "unknown-classes")],
        )

    def test_typos_names_each_typo_with_its_correction(self):
        found = evidence.typos((FIXTURES / "typos.jsonl").read_text(encoding="utf-8"), ROOT)

        self.assertEqual([(f["path"], f["line"], f["rule"]) for f in found][0], ("README.md", 3, "typo"))
        self.assertTrue(found[0]["detail"].endswith(" -> the"))

    def test_markdownlint_names_each_rule_by_its_number(self):
        self.assertEqual(
            read("markdownlint", "markdownlint.json"),
            [("docs/guide.md", 3, "MD009"), ("docs/guide.md", 5, "MD012"), ("docs/guide.md", 6, "MD012")],
        )

    def test_the_other_tools_each_name_their_rule_where_it_is_written(self):
        self.assertEqual(read("actionlint", "actionlint.json"), [(".github/workflows/w.yml", 6, "expression")])
        self.assertEqual(read("lychee", "lychee.json"), [("README.md", 4, "broken-link")])
        self.assertEqual(read("gitleaks", "gitleaks.json"), [("config.ini", 2, "secret")])
        self.assertEqual(read("osv-scanner", "osv-scanner.json"), [("composer.lock", 0, "vulnerability")])

    def test_zizmor_names_each_audit_at_its_primary_location_counted_from_one(self):
        self.assertEqual(read("zizmor", "zizmor.json"), [(".github/workflows/bad.yml", 11, "template-injection")])

    def test_zizmor_leaves_out_what_is_ignored_and_reads_a_finding_with_no_place(self):
        text = json.dumps([
            {"ident": "unpinned-uses", "ignored": True, "locations": []},
            {"ident": "excessive-permissions", "ignored": False, "locations": [
                {"symbolic": {"kind": "Related", "key": {"Local": {"verbatim_path": "./a.yml"}}}, "concrete": {"location": {"start_point": {"row": 1}}}},
                {"symbolic": {"kind": "Primary", "key": {"Local": {"verbatim_path": "./b.yml"}}}, "concrete": {"location": {"start_point": {"row": 4}}}},
            ]},
            {"ident": "dependabot-cooldown", "desc": "insufficient cooldown", "locations": []},
        ])

        self.assertEqual(
            [(f["path"], f["line"], f["rule"], f["detail"]) for f in evidence.zizmor(text, ROOT)],
            [("b.yml", 5, "excessive-permissions", ""), ("", 0, "dependabot-cooldown", "insufficient cooldown")],
        )

    def test_a_script_of_this_repository_is_read_as_it_wrote_its_findings(self):
        text = json.dumps([{"path": "", "line": 0, "rule": "conventional-subject", "detail": "abc12345"}])

        self.assertEqual(evidence.READERS["commitlint"](text, ROOT)[0]["rule"], "conventional-subject")


class Collect(unittest.TestCase):
    PRODUCERS = [
        {"step": "validate", "tool": "composer-validate"},
        {"step": "pint", "tool": "pint", "raw": "pint.json"},
        {"step": "phpstan", "tool": "phpstan", "raw": "phpstan.json"},
    ]

    def test_it_reads_the_tools_whose_steps_ran_and_skips_the_rest(self):
        outcomes = {"validate": {"outcome": "success"}, "pint": {"outcome": "failure"}, "phpstan": {"outcome": "skipped"}}
        raw = {"pint": "", "pint.json": (FIXTURES / "pint.json").read_text(encoding="utf-8")}

        tools, found = evidence.collect(self.PRODUCERS, outcomes, raw, ROOT)

        self.assertEqual(tools, ["composer-validate", "pint"])
        self.assertEqual({f["tool"] for f in found}, {"pint"})

    def test_a_failed_step_with_nothing_readable_is_one_finding_named_after_its_tool(self):
        outcomes = {"validate": {"outcome": "failure"}, "pint": {"outcome": "failure"}}

        tools, found = evidence.collect(self.PRODUCERS, outcomes, {"pint.json": "not json"}, ROOT)

        self.assertEqual([(f["tool"], f["rule"], f["path"]) for f in found], [("composer-validate", "composer-validate", ""), ("pint", "pint", "")])

    def test_it_writes_at_most_a_thousand_findings(self):
        lines = "\n".join(json.dumps({"type": "typo", "path": "a.md", "line_num": n, "typo": "qqq", "corrections": ["qqqq"]}) for n in range(1, 1500))

        _, found = evidence.collect([{"step": "typos", "tool": "typos", "raw": "t"}], {"typos": {"outcome": "failure"}}, {"t": lines}, ROOT)

        self.assertEqual(len(found), evidence.MOST_FINDINGS)


class Annotation(unittest.TestCase):
    def test_a_finding_is_an_error_on_its_line_with_its_words_escaped(self):
        item = evidence.finding("phpstan", "src/A,B.php", 3, "C5", "no else\n::stop-commands::x 100%")

        self.assertEqual(
            evidence.annotation(item),
            "::error file=src/A%2CB.php,line=3,title=phpstan C5::no else%0A::stop-commands::x 100%25",
        )

    def test_a_finding_with_no_file_is_an_error_on_the_run(self):
        self.assertEqual(evidence.annotation(evidence.finding("commitlint", "", 0, "conventional-subject")), "::error title=commitlint conventional-subject::conventional-subject")

    def test_a_detail_is_cut_short(self):
        self.assertEqual(len(evidence.finding("a", "", 0, "r", "x" * 1000)["detail"]), evidence.MOST_DETAIL)


class Names(unittest.TestCase):
    def test_a_called_workflows_gate_is_named_with_a_dash(self):
        self.assertEqual(evidence.file_name("hygiene/typos"), "hygiene-typos")

    def test_a_path_is_spelt_from_the_repository_root_whoever_ran_the_tool(self):
        self.assertEqual(evidence.relative(f"{ROOT}/src/A.php", ROOT), "src/A.php")
        self.assertEqual(evidence.relative("/github/workspace/composer.lock", ROOT), "composer.lock")
        self.assertEqual(evidence.relative("./README.md", ROOT), "README.md")


if __name__ == "__main__":
    unittest.main()
