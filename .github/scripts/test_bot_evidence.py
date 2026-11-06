"""The evidence artifact, read as hostile: python3 .github/scripts/unit.py"""
import io
import json
import unittest
import zipfile
from unittest import mock

import bot_evidence
import fake_github

GATES = {
    "checks": {"check": "checks", "fix": "composer lint:fix", "evidence": [{"tool": "pint"}, {"tool": "phpstan"}]},
    "hygiene/typos": {"check": "hygiene / typos", "evidence": [{"tool": "typos"}]},
}
NAMES = bot_evidence.expected(GATES)
PATCH = b"diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\n"


def archive(files: dict[str, bytes]) -> bytes:
    data = io.BytesIO()
    with zipfile.ZipFile(data, "w") as opened:
        for name, content in files.items():
            opened.writestr(name, content)
    return data.getvalue()


def document(gate: str, findings: list) -> bytes:
    return json.dumps({"format": 1, "job": gate, "tool": ["pint"], "findings": findings}).encode()


FINDING = {"tool": "pint", "path": "src/A.php", "line": 3, "rule": "single_quote", "detail": "words never shown"}


def tools_of(gate: str) -> list[str]:
    return [producer["tool"] for producer in GATES[gate]["evidence"]]


class Expected(unittest.TestCase):
    def test_each_gate_may_leave_its_findings_and_a_gate_with_a_fix_its_patch(self):
        self.assertEqual(NAMES, {
            "checks.json": ("checks", "findings"),
            "checks.fix.patch": ("checks", "patch"),
            "hygiene-typos.json": ("hygiene/typos", "findings"),
        })


class Unpack(unittest.TestCase):
    def test_only_the_expected_names_are_read(self):
        files = bot_evidence.unpack(archive({"checks.json": b"{}", "raw/checks.json": b"x", "../checks.json": b"x", "other": b"x"}), NAMES, 100, 1000)

        self.assertEqual(files, {"checks.json": b"{}"})

    def test_a_file_over_its_cap_is_left_and_the_total_cap_stops_reading(self):
        files = bot_evidence.unpack(archive({"checks.json": b"x" * 11, "hygiene-typos.json": b"y" * 5}), NAMES, 10, 1000)
        self.assertEqual(files, {"hygiene-typos.json": b"y" * 5})

        files = bot_evidence.unpack(archive({"checks.json": b"x" * 6, "hygiene-typos.json": b"y" * 6}), NAMES, 10, 10)
        self.assertEqual(files, {"checks.json": b"x" * 6})

    def test_a_size_the_archive_understates_is_caught_when_read(self):
        data = archive({"checks.json": b"x" * 50})
        opened = zipfile.ZipFile(io.BytesIO(data))
        entry = opened.infolist()[0]
        entry.file_size = 5
        with mock.patch.object(zipfile.ZipFile, "infolist", return_value=[entry]):
            self.assertEqual(bot_evidence.unpack(data, NAMES, 10, 1000), {})

    def test_a_name_twice_is_read_once(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, "w") as opened, self.assertWarns(UserWarning):
            opened.writestr("checks.json", b"1")
            opened.writestr("checks.json", b"2")

        self.assertEqual(bot_evidence.unpack(data.getvalue(), NAMES, 10, 1000), {"checks.json": b"1"})

    def test_what_is_no_zip_holds_nothing(self):
        self.assertEqual(bot_evidence.unpack(b"not a zip", NAMES, 10, 10), {})

    def test_a_member_that_cannot_be_read_is_left(self):
        data = archive({"checks.json": b"{}"})
        with mock.patch.object(zipfile.ZipFile, "open", side_effect=zipfile.BadZipFile):
            self.assertEqual(bot_evidence.unpack(data, NAMES, 10, 1000), {})


class Findings(unittest.TestCase):
    def test_a_valid_document_gives_its_findings_without_their_words(self):
        found = bot_evidence.findings("checks", tools_of("checks"), document("checks", [FINDING]))

        self.assertEqual(found, [{"tool": "pint", "path": "src/A.php", "line": 3, "rule": "single_quote"}])

    def test_a_rule_may_be_a_rector_class(self):
        finding = {**FINDING, "tool": "phpstan", "rule": "Rector\\\\Php\\\\Rule"}

        self.assertEqual(bot_evidence.findings("checks", tools_of("checks"), document("checks", [finding]))[0]["rule"], "Rector\\\\Php\\\\Rule")

    def test_a_document_off_its_schema_is_dropped_whole(self):
        bad = {
            "not json": b"{",
            "not utf-8": b"\xff",
            "not an object": b"[]",
            "another format": json.dumps({"format": 2, "job": "checks", "findings": []}).encode(),
            "another job": document("hygiene/typos", []),
            "no list": json.dumps({"format": 1, "job": "checks", "findings": {}}).encode(),
            "not a finding": document("checks", [FINDING, "x"]),
            "a tool the gate does not run": document("checks", [{**FINDING, "tool": "typos"}]),
            "a path outside": document("checks", [{**FINDING, "path": "../x"}]),
            "a path that is no string": document("checks", [{**FINDING, "path": 3}]),
            "a line that is no number": document("checks", [{**FINDING, "line": "3"}]),
            "a line that is a boolean": document("checks", [{**FINDING, "line": True}]),
            "a negative line": document("checks", [{**FINDING, "line": -1}]),
            "a rule with markup": document("checks", [{**FINDING, "rule": "<b>x</b>"}]),
            "a rule that is no string": document("checks", [{**FINDING, "rule": 1}]),
        }
        for why, data in bad.items():
            with self.subTest(why):
                self.assertIsNone(bot_evidence.findings("checks", tools_of("checks"), data))

    def test_a_finding_of_no_file_is_kept(self):
        found = bot_evidence.findings("checks", tools_of("checks"), document("checks", [{**FINDING, "path": "", "line": 0}]))

        self.assertEqual(found[0]["path"], "")


class Read(unittest.TestCase):
    def test_the_valid_findings_and_fixes_are_kept_by_gate(self):
        files = {
            "checks.json": document("checks", [FINDING]),
            "checks.fix.patch": PATCH,
            "hygiene-typos.json": b"not json",
        }

        found, fixes = bot_evidence.read(files, NAMES, tools_of)

        self.assertEqual(list(found), ["checks"])
        self.assertEqual([patch.path for patch in fixes["checks"]], ["src/A.php"])

    def test_a_patch_the_bot_does_not_apply_is_dropped(self):
        _, fixes = bot_evidence.read({"checks.fix.patch": b"diff --git a/x b/y\n"}, NAMES, tools_of)
        self.assertEqual(fixes, {})

        _, fixes = bot_evidence.read({"checks.fix.patch": b"\xff"}, NAMES, tools_of)
        self.assertEqual(fixes, {})

        _, fixes = bot_evidence.read({"checks.fix.patch": b""}, NAMES, tools_of)
        self.assertEqual(fixes, {})


class Fetch(unittest.TestCase):
    LIMITS = {"most_archive_bytes": 1000, "most_file_bytes": 100, "most_total_bytes": 1000}

    def test_it_reads_the_run_s_evidence_artifact(self):
        github = fake_github.GitHub({("GET", "actions/runs/5/artifacts"): {"artifacts": [{"id": 9, "name": "evidence", "expired": False, "size_in_bytes": 10}]}})
        github.archives[9] = archive({"checks.json": b"{}"})
        with fake_github.running(github):
            self.assertEqual(bot_evidence.fetch(5, NAMES, self.LIMITS), {"checks.json": b"{}"})

        self.assertEqual(github.asked[0][1], "actions/runs/5/artifacts?name=evidence&per_page=10")

    def test_no_artifact_an_expired_one_or_one_over_the_cap_gives_nothing(self):
        for artifacts in ([], [{"id": 9, "name": "evidence", "expired": True}], [{"id": 9, "name": "evidence", "size_in_bytes": 5000}], [{"id": 9, "name": "other"}]):
            github = fake_github.GitHub({("GET", "actions/runs/5/artifacts"): {"artifacts": artifacts}})
            with self.subTest(artifacts), fake_github.running(github):
                self.assertEqual(bot_evidence.fetch(5, NAMES, self.LIMITS), {})


if __name__ == "__main__":
    unittest.main()
