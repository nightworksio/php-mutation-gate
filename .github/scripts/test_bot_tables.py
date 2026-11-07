"""main's tables, as the bot reads them: python3 .github/scripts/unit.py"""
import re
import unittest

import bot_tables
import fake_github

ROOT = fake_github.ROOT
SOURCES = "\n".join(path.read_text(encoding="utf-8") for path in sorted((ROOT / ".github" / "scripts").glob("bot_*.py")))
LITERALS = set(re.findall(r"""["']([a-z][a-z-]*)["']""", SOURCES))


class Reading(unittest.TestCase):
    def test_a_package_rule_is_read_with_its_line(self):
        rules = bot_tables.package_rules("intro\n| **A1** | Core names nothing | arch |\n| B2 | x | y |\nnot | a | row")

        self.assertEqual(rules, {"A1": ("Core names nothing", 2), "B2": ("x", 3)})

    def test_the_slugs_are_the_guide_s_sections(self):
        self.assertEqual(bot_tables.slugs("# Troubleshooting\n## no-tree\ntext\n## Not A Slug\n## two-runners\n"), {"no-tree", "two-runners"})

    def test_a_job_is_matched_to_its_gate_whatever_its_expressions_filled(self):
        gates = {"shard": {"check": "mutation / shard ${{ matrix.shard.id }} (${{ matrix.label }})"}, "checks": {"check": "checks"}}

        self.assertEqual(bot_tables.gate_of("mutation / shard 3 (src)", gates), "shard")
        self.assertEqual(bot_tables.gate_of("checks", gates), "checks")
        self.assertEqual(bot_tables.gate_of("checks again", gates), "")

    def test_a_placeholder_with_no_value_is_an_error(self):
        with self.assertRaises(KeyError):
            bot_tables.fill("Only {who}.", {})

    def test_a_gate_that_says_why_it_leaves_no_evidence_has_no_producers(self):
        self.assertEqual(bot_tables.producers({"evidence": "none"}), [])
        self.assertEqual(bot_tables.producers({"evidence": [{"tool": "x"}]}), [{"tool": "x"}])


class Main(unittest.TestCase):
    tables = bot_tables.Tables(ROOT)

    def test_it_reads_every_table_of_main(self):
        self.assertIn("checks", self.tables.gates)
        self.assertIn("argument.type", self.tables.rules["phpstan"])
        self.assertIn("A1", self.tables.package)
        self.assertIn("no-coverage-driver", self.tables.slugs)
        self.assertEqual(self.tables.decisions["0019"][:len(".docs/decisions/0019-")], ".docs/decisions/0019-")
        self.assertIn("commit_parsers", self.tables.cliff)

    def test_a_rule_is_explained_by_its_table_the_package_or_its_gate(self):
        says, link = self.tables.explanation("checks", "phpstan", "argument.type")
        self.assertTrue(link.startswith("https://phpstan.org/"))
        self.assertTrue(says)

        says, link = self.tables.explanation("checks", "phpstan", "A1")
        self.assertEqual((says, link), (self.tables.package["A1"][0], f"ARCHITECTURE.md#L{self.tables.package['A1'][1]}"))

        architecture = self.tables.rules["phpstan"]["mutationGate.broadCatch"]["architecture"]
        says, link = self.tables.explanation("checks", "phpstan", "mutationGate.broadCatch")
        self.assertEqual((says, link), (self.tables.package[architecture][0], f"ARCHITECTURE.md#L{self.tables.package[architecture][1]}"))

        says, link = self.tables.explanation("tests", "coverage", "uncovered")
        self.assertEqual(link, ".docs/guide/troubleshooting.md#no-coverage-driver")

        says, link = self.tables.explanation("checks", "composer-audit", "advisory")
        self.assertEqual(link, "")

    def test_a_rule_outside_the_vocabulary_or_a_tool_the_gate_does_not_run_is_unexplained(self):
        self.assertIsNone(self.tables.explanation("checks", "phpstan", "made.up"))
        self.assertIsNone(self.tables.explanation("checks", "pest", "failure"))
        self.assertIsNone(self.tables.explanation("nonsense", "phpstan", "argument.type"))
        self.tables.rules["pint"]["no_words"] = {"architecture": "Z99"}
        try:
            self.assertIsNone(self.tables.explanation("checks", "pint", "no_words"))
        finally:
            del self.tables.rules["pint"]["no_words"]

    def test_a_sentence_is_filled_from_the_words_table(self):
        self.assertEqual(self.tables.say("a-job"), self.tables.words["says"]["a-job"])


class Words(unittest.TestCase):
    tables = bot_tables.Tables(ROOT)

    def test_every_sentence_the_scripts_say_is_in_the_table(self):
        said = set(re.findall(r"""say\(\s*["']([a-z-]+)["']""", SOURCES))

        self.assertEqual(sorted(said - set(self.tables.words["says"])), [])

    def test_every_sentence_of_the_table_is_said(self):
        self.assertEqual(sorted(set(self.tables.words["says"]) - LITERALS), [])

    def test_every_item_of_the_checklist_is_used_and_has_both_states(self):
        for name, item in self.tables.words["checklist"].items():
            self.assertIn(name, LITERALS)
            self.assertEqual(sorted(item), ["done", "todo"])

    def test_every_reason_names_a_kind_of_infrastructure_step(self):
        self.assertEqual(sorted(self.tables.words["reasons"]), sorted(self.tables.config["rerun"]["steps"]))


class Config(unittest.TestCase):
    tables = bot_tables.Tables(ROOT)

    def test_the_relayed_workflows_exist_and_the_relay_names_them(self):
        relay = (ROOT / ".github" / "workflows" / "bot-relay.yml").read_text(encoding="utf-8")
        for path in self.tables.config["relay"]["workflows"].values():
            self.assertTrue((ROOT / path).is_file(), path)
        names = re.search(r"workflows: \[([^\]]*)\]", relay)[1].split(", ")

        self.assertEqual(sorted(names), sorted(self.tables.config["relay"]["workflows"]))

    def test_the_markdown_fix_keeps_the_findings_the_explainer_reads(self):
        hygiene = (ROOT / ".github" / "workflows" / "hygiene.yml").read_text(encoding="utf-8")
        step = hygiene[hygiene.index("compute the mechanical fixes"):]

        self.assertLess(step.index('cp evidence/raw/markdownlint.json "${RUNNER_TEMP}/'), step.index("--fix"))
        self.assertLess(step.index("--fix"), step.index('" evidence/raw/markdownlint.json'))

    def test_the_explainer_starts_on_the_comment_the_relay_opens_and_the_one_it_edits(self):
        explain = (ROOT / ".github" / "workflows" / "bot-explain.yml").read_text(encoding="utf-8")

        self.assertIn("types: [created, edited]", explain)

    def test_the_gates_the_comment_and_the_fix_name_exist(self):
        self.assertIn(self.tables.config["comment"]["verdict_gate"], self.tables.gates)
        for gate in self.tables.config["fix"]["beyond_the_diff"]:
            self.assertIn("fix", self.tables.gates[gate])

    def test_the_gate_s_marker_is_the_one_its_comment_carries(self):
        markdown = (ROOT / "src" / "Core" / "Report" / "Markdown.php").read_text(encoding="utf-8")

        self.assertIn(f"MARKER = '{self.tables.config['comment']['gate_marker']}'", markdown)


if __name__ == "__main__":
    unittest.main()
