"""The sticky comment's text: python3 .github/scripts/unit.py"""
import dataclasses
import unittest

import bot_patch
import bot_render
import bot_sticky
import bot_tables
import fake_github

TABLES = bot_tables.Tables(fake_github.ROOT)
WEB = fake_github.WEB
SHA = "c" * 40
BLOB = f"{WEB}/blob/main"
RUN = f"{WEB}/actions/runs/1"
JOB = f"{WEB}/actions/runs/1/job/2"


def pull(**changes) -> bot_render.Pull:
    values = {
        "number": 7,
        "sha": SHA,
        "draft": False,
        "association": "MEMBER",
        "title": "feat(pest): a faster worker",
        "body": "Why.\n\nSpec: 0019",
        "same_repo": True,
    }
    return bot_render.Pull(**{**values, **changes})


def run(key: str, status: str = "completed", conclusion: str = "success") -> bot_render.Run:
    return bot_render.Run(key=key, id=1, status=status, conclusion=conclusion, url=RUN)


def state(**changes) -> bot_render.State:
    values = {
        "pull": pull(),
        "files": {"src/Adapter/Pest/Worker.php": [(1, 30)], ".github/workflows/ci.yml": [(1, 2)]},
        "runs": {"ci": run("ci"), "pr": run("pr")},
        "blob": BLOB,
    }
    return bot_render.State(**{**values, **changes})


def failed(gate: str, conclusion: str = "failure") -> bot_render.Job:
    return bot_render.Job(gate=gate, conclusion=conclusion, url=JOB)


PATCH = bot_patch.parse(
    "diff --git a/src/Adapter/Pest/Worker.php b/src/Adapter/Pest/Worker.php\n"
    "@@ -2,1 +2,1 @@\n-b\n+B\n"
    "@@ -40,1 +40,1 @@\n-x\n+X\n"
)


class Code(unittest.TestCase):
    def test_inline_code_outgrows_the_backticks_it_holds(self):
        self.assertEqual(bot_render.code("a"), "`a`")
        self.assertEqual(bot_render.code("a`b"), "`` a`b ``")

    def test_a_fence_outgrows_the_fences_it_holds(self):
        self.assertEqual(bot_render.fence("x", "diff"), "```diff\nx\n```")
        self.assertEqual(bot_render.fence("````\n`"), "`````\n````\n`\n`````")

    def test_a_link_of_main_is_made_absolute_and_a_web_link_kept(self):
        self.assertEqual(bot_render.link_of("ARCHITECTURE.md#L3", BLOB), f"{BLOB}/ARCHITECTURE.md#L3")
        self.assertEqual(bot_render.link_of("https://phpstan.org/x", BLOB), "https://phpstan.org/x")

    def test_a_check_s_expressions_are_left_out(self):
        self.assertEqual(bot_render.check_name({"check": "mutation / shard ${{ matrix.shard.id }}"}), "mutation / shard …")


class Headline(unittest.TestCase):
    def test_each_state_of_ci_has_its_headline(self):
        self.assertIn("failed", bot_render.headline(state(), TABLES, 2))
        self.assertIn("approve", bot_render.headline(state(waiting=True), TABLES, 0))
        self.assertIn("running", bot_render.headline(state(runs={"ci": run("ci", "in_progress", ""), "pr": run("pr")}), TABLES, 0))
        self.assertIn("running", bot_render.headline(state(runs={"ci": None, "pr": run("pr")}), TABLES, 0))
        self.assertIn("passed", bot_render.headline(state(), TABLES, 0))
        self.assertIn("`ccccccc`", bot_render.headline(state(), TABLES, 0))


class Checklist(unittest.TestCase):
    def items(self, **changes) -> dict[str, bool]:
        found = bot_render.checklist(state(**changes), TABLES)
        words = TABLES.words["checklist"]
        named = {}
        for done, text in found:
            for name, item in words.items():
                if text.startswith(item["done" if done else "todo"].split("{")[0]):
                    named[name] = done
        return named

    def test_a_ready_green_pull_request_has_every_item_done(self):
        jobs = {"ci": [bot_render.Job(gate="mutation/verdict", conclusion="success", url=JOB)]}

        self.assertEqual(self.items(jobs=jobs), {"title": True, "gates": True, "tested": True, "spec": True, "ready": True})

    def test_each_item_that_is_not_done_says_so(self):
        jobs = {"ci": [failed("mutation/verdict")]}
        found = self.items(
            pull=pull(title="no", body="", draft=True, association="FIRST_TIMER"),
            jobs=jobs,
            waiting=True,
            runs={"ci": run("ci", "completed", "failure"), "pr": None},
        )

        self.assertEqual(found, {"approved": False, "title": False, "gates": False, "tested": False, "spec": False, "ready": False})

    def test_a_spec_that_is_no_decision_is_not_done_and_paths_no_decision_holds_ask_for_none(self):
        self.assertFalse(self.items(pull=pull(body="Spec: 9999"))["spec"])
        self.assertNotIn("spec", self.items(files={"nothing/mapped.txt": []}))

    def test_the_spec_item_links_the_decisions_the_paths_belong_to(self):
        text = [text for done, text in bot_render.checklist(state(pull=pull(body="")), TABLES) if "Spec:" in text][0]

        self.assertIn(f"[0004]({BLOB}/.docs/decisions/0004-", text)

    def test_done_items_collapse(self):
        rendered = bot_render.render_checklist([(False, "a"), (True, "b"), (True, "c")], TABLES)

        self.assertEqual(rendered, "- [ ] a\n\n<details><summary>Done: 2</summary>\n\n- [x] b\n- [x] c\n\n</details>")
        self.assertEqual(bot_render.render_checklist([(True, "b")], TABLES), "<details><summary>Done: 1</summary>\n\n- [x] b\n\n</details>")
        self.assertEqual(bot_render.render_checklist([(False, "a")], TABLES), "- [ ] a")


class Failures(unittest.TestCase):
    def test_each_failed_gate_is_listed_once_and_jobs_outside_main_s_gates_are_counted(self):
        jobs = {"ci": [failed("checks"), failed("checks"), failed(""), failed("tests", "success")], "pr": [failed("commitlint", "timed_out")]}

        self.assertEqual(bot_render.failed_gates(state(jobs=jobs)), ([("checks", JOB), ("commitlint", JOB)], 1))

    def test_findings_are_grouped_by_file_and_rule_in_main_s_words(self):
        findings = {"checks": [
            {"tool": "phpstan", "path": "src/Adapter/Pest/Worker.php", "line": 9, "rule": "argument.type"},
            {"tool": "phpstan", "path": "src/Adapter/Pest/Worker.php", "line": 3, "rule": "argument.type"},
            {"tool": "pint", "path": "src/Adapter/Pest/Worker.php", "line": 0, "rule": "single_quote"},
            {"tool": "phpstan", "path": "", "line": 0, "rule": "phpstan"},
            {"tool": "phpstan", "path": "src/Elsewhere.php", "line": 1, "rule": "argument.type"},
            {"tool": "phpstan", "path": "src/Adapter/Pest/Worker.php", "line": 1, "rule": "made.up"},
            {"tool": "composer-normalize", "path": "composer.json", "line": 0, "rule": "composer-normalize"},
        ]}

        lines = bot_render.explained("checks", state(findings=findings), TABLES, 30)

        self.assertEqual(len(lines), 6)
        self.assertTrue(lines[0].startswith("- `phpstan`."))
        self.assertTrue(lines[1].startswith("- `composer.json`: `composer-normalize`."))
        self.assertTrue(lines[2].startswith("- `src/Adapter/Pest/Worker.php`, line 3, 9: `argument.type`."))
        self.assertIn("[More](https://phpstan.org/", lines[2])
        self.assertTrue(lines[3].startswith("- `src/Adapter/Pest/Worker.php`: `single_quote`."))
        self.assertIn("main does not explain: 1", lines[4])
        self.assertIn("does not change: 1", lines[5])

    def test_findings_past_the_cap_are_counted(self):
        findings = {"checks": [{"tool": "phpstan", "path": "", "line": 0, "rule": rule} for rule in ("argument.type", "phpstan")]}

        lines = bot_render.explained("checks", state(findings=findings), TABLES, 1)

        self.assertEqual(len(lines), 2)
        self.assertIn("left out of this comment: 1", lines[1])

    def test_a_gate_section_says_what_it_checks_how_to_reproduce_and_fix_it(self):
        section = bot_render.gate_section("checks", JOB, state(), TABLES, 30)

        self.assertTrue(section.startswith("#### `checks` failed"))
        self.assertIn(TABLES.gates["checks"]["checks"], section)
        self.assertIn("left no evidence", section)
        self.assertIn(f"Reproduce: `{TABLES.gates['checks']['reproduce']}`", section)
        self.assertIn("comment `/fix`", section)

    def test_a_fork_is_pointed_at_the_suggestions_and_a_gate_with_no_fix_says_so(self):
        self.assertIn("suggested changes", bot_render.gate_section("checks", JOB, state(pull=pull(same_repo=False)), TABLES, 30))
        self.assertIn("not mechanical", bot_render.gate_section("rules", JOB, state(), TABLES, 30))

    def test_a_gate_that_leaves_no_evidence_by_design_does_not_ask_for_it(self):
        self.assertNotIn("left no evidence", bot_render.gate_section("scope", JOB, state(), TABLES, 30))

    def test_the_verdict_links_the_gate_s_comment_or_its_run(self):
        with_comment = bot_render.gate_section("mutation/verdict", JOB, state(gate_comment=f"{WEB}/pull/7#issuecomment-1"), TABLES, 30)
        without = bot_render.gate_section("mutation/verdict", JOB, state(), TABLES, 30)

        self.assertIn(f"[the gate's comment]({WEB}/pull/7#issuecomment-1)", with_comment)
        self.assertIn(f"[the run]({JOB})", without)
        self.assertNotIn("Reproduce", without)


class Fixes(unittest.TestCase):
    HEAD = "\n".join(["a", "b", *["-"] * 37, "x"]) + "\n"

    def test_a_hunk_in_the_diff_that_matches_the_head_is_a_suggestion_and_the_rest_a_patch(self):
        inside, outside = bot_render.split_fixes(state(fixes={"checks": PATCH}, heads={"src/Adapter/Pest/Worker.php": self.HEAD}), TABLES)

        self.assertEqual(inside, [bot_patch.Suggestion("src/Adapter/Pest/Worker.php", 2, 2, ("B",))])
        self.assertEqual([hunk.old_start for patch in outside for hunk in patch.hunks], [40])

    def test_a_file_whose_head_is_unknown_is_left_to_the_patch(self):
        inside, outside = bot_render.split_fixes(state(fixes={"checks": PATCH}), TABLES)

        self.assertEqual(inside, [])
        self.assertEqual(len(outside[0].hunks), 2)

    def test_a_fix_outside_the_diff_or_under_a_path_never_written_is_not_shown(self):
        elsewhere = bot_patch.parse("diff --git a/src/Elsewhere.php b/src/Elsewhere.php\n@@ -1 +1 @@\n-a\n+b\n")
        workflow = bot_patch.parse("diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml\n@@ -1 +1 @@\n-a\n+b\n")

        self.assertEqual(bot_render.split_fixes(state(fixes={"checks": elsewhere + workflow}), TABLES), ([], []))

    def test_composer_json_is_within_reach_of_the_checks_fix(self):
        normalize = bot_patch.parse("diff --git a/composer.json b/composer.json\n@@ -1 +1 @@\n-a\n+b\n")

        _, outside = bot_render.split_fixes(state(fixes={"checks": normalize}), TABLES)

        self.assertEqual([patch.path for patch in outside], ["composer.json"])

    def test_a_suggestion_is_a_fenced_suggestion(self):
        self.assertEqual(bot_render.suggestion_body(bot_patch.Suggestion("x", 1, 1, ("a", "b"))), "```suggestion\na\nb\n```")


class Help(unittest.TestCase):
    def test_a_conventional_title_shows_its_changelog_line_in_a_code_block(self):
        help_ = bot_render.text_help(state(), TABLES)

        self.assertEqual(help_[0], "**Changelog.** Merged, this pull request adds to *Added*:\n\n```markdown\n- **pest:** A faster worker\n```")

    def test_a_title_the_changelog_leaves_out_says_so(self):
        self.assertIn("adds no line", bot_render.text_help(state(pull=pull(title="docs: words")), TABLES)[0])

    def test_an_unconventional_title_gets_a_suggestion_from_the_paths_and_a_missing_spec_one_too(self):
        help_ = bot_render.text_help(state(pull=pull(title="Worker", body=""), files={"src/Adapter/Pest/Worker.php": []}), TABLES)

        self.assertEqual(help_[0], "**Title.** A conventional title for these changes starts with `<type>(pest): `.")
        self.assertIn("[0004]", help_[1])


class Reran(unittest.TestCase):
    def test_the_jobs_re_run_are_named_with_why_while_they_run_and_after(self):
        reran = [bot_render.Reran(name="checks", url=JOB, kind="checkout")]

        running = bot_render.reran_line(state(reran=reran, runs={"ci": run("ci", "in_progress", ""), "pr": run("pr")}), TABLES)
        done = bot_render.reran_line(state(reran=reran), TABLES)

        self.assertEqual(running, f"Re-running after an infrastructure failure: [checks]({JOB}) (checking out the code failed).")
        self.assertTrue(done.startswith("Re-ran once"))
        self.assertEqual(bot_render.reran_line(state(), TABLES), "")


class Render(unittest.TestCase):
    def test_the_whole_comment_carries_its_marks_and_every_part(self):
        body = bot_render.render(
            state(
                pull=pull(association="FIRST_TIME_CONTRIBUTOR"),
                jobs={"ci": [failed("checks"), failed("")]},
                reran=[bot_render.Reran(name="a job", url=JOB, kind="runner")],
            ),
            TABLES,
            PATCH,
            SHA,
        )

        self.assertTrue(body.startswith(f"{bot_sticky.STICKY}\n{bot_sticky.suggested_mark(SHA)}\nThank you for your first pull request"))
        self.assertIn(f"{BLOB}/CONTRIBUTING.md#setup", body)
        self.assertIn("### Gates that failed on `ccccccc`: 2.", body)
        self.assertIn("changes CI itself", body)
        self.assertIn("#### `checks` failed", body)
        self.assertIn(f"Jobs outside main's gates that failed: 1. See [the run]({RUN}).", body)
        self.assertIn("Re-ran once", body)
        self.assertIn("<details><summary>The fix CI computed, outside this pull request's diff</summary>\n\n```diff\n--- a/src/", body)
        self.assertTrue(body.endswith("</details>\n"))

    def test_a_quiet_comment_has_no_mark_but_its_own(self):
        body = bot_render.render(state(files={"README.md": []}), TABLES, [], "")

        self.assertTrue(body.startswith(f"{bot_sticky.STICKY}\n### Every gate passed"))
        self.assertNotIn("<details><summary>The fix", body)

    def test_a_comment_over_the_cap_cuts_its_findings_and_then_its_patch(self):
        findings = {"checks": [{"tool": "phpstan", "path": "src/Adapter/Pest/Worker.php", "line": n, "rule": rule} for n, rule in enumerate(sorted(TABLES.rules["phpstan"]), 1)]}
        tables = bot_tables.Tables(fake_github.ROOT)
        tables.config = {**tables.config, "comment": {**tables.config["comment"], "most_characters": 3000}}
        big = state(jobs={"ci": [failed("checks")]}, findings=findings, fixes={"checks": PATCH})

        long_patch = [bot_patch.FilePatch("src/Adapter/Pest/Worker.php", (bot_patch.Hunk(1, tuple(f"-{n}" for n in range(200))),))]

        body = bot_render.render(big, tables, long_patch, "")

        self.assertIn("left out of this comment", body)
        self.assertIn("is too long for this comment. `composer refactor:fix", body)

    def test_runs_that_are_missing_link_no_run(self):
        body = bot_render.render(state(runs={"ci": None, "pr": None}, jobs={"ci": [failed("")]}), TABLES, [], "")

        self.assertNotIn("Jobs outside", body)


if __name__ == "__main__":
    unittest.main()
