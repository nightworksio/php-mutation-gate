"""The deciding half of alone.py: python3 -m unittest discover .github/scripts"""
import tempfile
import unittest
from pathlib import Path

import alone


class TestFiles(unittest.TestCase):
    def test_lists_the_test_files_a_narrowed_run_can_load_in_byte_order(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for path in (
                "tests/Unit/Core/MoneyTest.php",
                "tests/Unit/Core/Money.php",
                "tests/Process/RunTest.php",
                "tests/Contract/RunnerTest.php",
                "tests/Arch/LayersTest.php",
                "plugins/default/tests/ReturnsTest.php",
                "tests/Unit/Core/a/ZTest.php",
            ):
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                (root / path).write_text("<?php\n", encoding="utf-8")

            self.assertEqual(
                alone.test_files(root),
                [
                    "plugins/default/tests/ReturnsTest.php",
                    "tests/Process/RunTest.php",
                    "tests/Unit/Core/MoneyTest.php",
                    "tests/Unit/Core/a/ZTest.php",
                ],
            )


class Judge(unittest.TestCase):
    def test_finds_nothing_where_every_file_passed_alone(self):
        self.assertEqual(alone.judge({"tests/Unit/ATest.php": (0, "OK")}), [])

    def test_names_each_file_that_failed_alone_with_the_end_of_its_output(self):
        found = alone.judge({
            "tests/Unit/BTest.php": (1, "x" * 2000 + "Call to undefined function money()"),
            "tests/Unit/ATest.php": (2, "Fatal"),
            "tests/Unit/CTest.php": (0, "OK"),
        })

        self.assertEqual([item["path"] for item in found], ["tests/Unit/ATest.php", "tests/Unit/BTest.php"])
        self.assertEqual({item["rule"] for item in found}, {"fails-alone"})
        self.assertEqual(len(found[1]["detail"]), alone.TAIL)
        self.assertTrue(found[1]["detail"].endswith("Call to undefined function money()"))


if __name__ == "__main__":
    unittest.main()
