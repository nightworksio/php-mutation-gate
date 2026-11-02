"""The deciding half of leg_support.py: python3 -m unittest discover .github/scripts"""
import re
import unittest

import leg_support

LISTING = """
  INFO  Available tests:

 - P\\Tests\\Contract\\Runner\\KillsTest::__pest_evaluable_it_kills"dataset "phpunit""
 - P\\Tests\\Contract\\Runner\\KillsTest::__pest_evaluable_it_names_its_killer
 - P\\Tests\\Contract\\TreeSource\\TreeSourceTest::__pest_evaluable_it_reads_a_tree
"""

SUPPORT = {
    "tests/Support/Scratch.php": "final class Scratch { use Tree; }",
    "tests/Support/Tree.php": "trait Tree { public function at(): Moment {} }",
    "tests/Support/Moment.php": "final class Moment {}",
    "tests/Support/Growth.php": "final class Growth { public function of(): Scratching {} }",
    "tests/Support/Mutators/PlusToMinus.php": "final class PlusToMinus {}",
}


class Listing(unittest.TestCase):
    def test_reads_each_listed_test_s_file_once(self):
        self.assertEqual(leg_support.listed_files(LISTING),
                         ["tests/Contract/Runner/KillsTest.php", "tests/Contract/TreeSource/TreeSourceTest.php"])

    def test_reads_no_file_from_a_listing_of_nothing(self):
        self.assertEqual(leg_support.listed_files("  INFO  No tests found.\n"), [])


class Support(unittest.TestCase):
    def test_follows_the_support_a_test_names_through_the_support_it_names(self):
        found = leg_support.support_of(["$dir = Scratch::directory();"], SUPPORT)
        self.assertEqual(found, ["tests/Support/Moment.php", "tests/Support/Scratch.php", "tests/Support/Tree.php"])

    def test_names_a_class_only_as_a_whole_word_and_finds_one_in_a_subdirectory(self):
        self.assertEqual(leg_support.support_of(["Scratching(); PlusToMinus::class"], SUPPORT),
                         ["tests/Support/Mutators/PlusToMinus.php"])

    def test_says_what_a_list_misses_and_what_it_holds_beyond_use(self):
        self.assertEqual(leg_support.differences(["a.php", "b.php"], ["b.php", "c.php"]), ["missing c.php", "not used a.php"])
        self.assertEqual(leg_support.differences(["a.php"], ["a.php"]), [])


class TheTable(unittest.TestCase):
    def test_holds_every_key_a_leg_s_steps_read_and_a_sorted_list_of_existing_support(self):
        for runner, leg in leg_support.legs().items():
            self.assertEqual(sorted(leg), sorted([*leg_support.FIXTURE, "support"]), runner)
            self.assertTrue((leg_support.ROOT / leg["dir"]).is_dir(), runner)
            self.assertEqual(leg["support"], sorted(leg["support"]), runner)
            for path in leg["support"]:
                self.assertTrue(path.startswith(leg_support.SUPPORT) and (leg_support.ROOT / path).is_file(), path)

    def test_agrees_with_the_canary_s_run_of_each_leg_on_the_newest_releases(self):
        canary = (leg_support.ROOT / ".github/workflows/canary.yml").read_text(encoding="utf-8")
        runs = re.findall(r"^      - id: ([a-z0-9-]+)-newest\n        run: (.*?)\n        env:\n          ([A-Z_]+): '?([a-z0-9]+)'?$",
                          canary, re.M | re.S)
        self.assertGreaterEqual(len(runs), 7)
        table = leg_support.legs()
        for runner, command, variable, value in runs:
            leg = table[runner if runner in table else f"{runner}-13"]
            given = re.search(r"--filter='([^']*)'", command)
            self.assertEqual(given.group(1) if given else "", leg["filter"], runner)
            self.assertEqual(value, leg["contracts"] if variable == "RUNNER_CONTRACTS" else leg["analysers"], runner)


if __name__ == "__main__":
    unittest.main()
