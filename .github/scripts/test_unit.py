"""The deciding half of unit.py: python3 -m unittest discover .github/scripts"""
import unittest
from pathlib import Path

import unit

ROOT = Path("/home/runner/work/php-mutation-gate/php-mutation-gate")
FILE = ROOT / ".github" / "scripts" / "test_money.py"
TRACE = f"""Traceback (most recent call last):
  File "{FILE}", line 12, in test_adds
    self.assertEqual(add(1, 1), 3)
  File "{ROOT}/.github/scripts/money.py", line 4, in add
    return a + b
AssertionError: 2 != 3
"""


class Where(unittest.TestCase):
    def test_it_is_the_last_line_of_the_tests_own_file_the_trace_passes(self):
        self.assertEqual(unit.where(FILE, TRACE, ROOT), (".github/scripts/test_money.py", 12))

    def test_it_is_no_line_where_the_trace_never_passes_the_tests_file(self):
        self.assertEqual(unit.where(FILE, "Traceback (most recent call last):\nboom\n", ROOT), (".github/scripts/test_money.py", 0))


class Findings(unittest.TestCase):
    def test_each_failed_and_broken_test_is_named_by_its_id(self):
        class Planted(unittest.TestCase):
            def test_fails(self):
                self.fail("no")

            def test_breaks(self):
                raise RuntimeError("boom")

            def test_passes(self):
                pass

        result = unittest.TestResult()
        unittest.defaultTestLoader.loadTestsFromTestCase(Planted).run(result)

        found = unit.findings(result)

        self.assertEqual(sorted((f["rule"], f["detail"].rsplit(".", 1)[1]) for f in found), [("error", "test_breaks"), ("failure", "test_fails")])
        self.assertTrue(all(f["path"] == ".github/scripts/test_unit.py" and f["line"] > 0 for f in found))


if __name__ == "__main__":
    unittest.main()
