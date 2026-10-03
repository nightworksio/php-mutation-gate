"""The deciding half of runner_pins.py: python3 -m unittest discover .github/scripts"""
import json
import unittest

import runner_pins

MANIFEST = {
    "require-dev": {"infection/infection": "~0.35.0"},
    "conflict": {"pestphp/pest-plugin-mutate": "<5.0.2 || >5.0.2", "pestphp/pest": "<5.1"},
}


class Satisfies(unittest.TestCase):
    def test_each_comparison_as_composer_reads_it(self):
        cases = [
            ("5.0.2", "<5.0.2", False),
            ("5.0.1", "<5.0.2", True),
            ("5.0.2", "<=5.0.2", True),
            ("5.0.3", ">5.0.2", True),
            ("5.0.2", ">=5.0.2", True),
            ("5.0.2", "!=5.0.2", False),
            ("5.0.2", "5.0.2", True),
            ("5.0.2", "==5.0.2", True),
            ("5.1", "5.1.0", True),
            ("v5.0.2", "=5.0.2", True),
        ]
        for release, constraint, met in cases:
            with self.subTest(release=release, constraint=constraint):
                self.assertIs(runner_pins.satisfies(release, constraint), met)

    def test_a_tilde_lets_the_last_written_number_but_one_rise(self):
        self.assertTrue(runner_pins.satisfies("0.35.6", "~0.35.0"))
        self.assertFalse(runner_pins.satisfies("0.36.0", "~0.35.0"))
        self.assertTrue(runner_pins.satisfies("1.9.0", "~1.2"))
        self.assertFalse(runner_pins.satisfies("2.0.0", "~1.2"))
        self.assertTrue(runner_pins.satisfies("1.4.0", "~1"))

    def test_a_caret_lets_a_release_rise_below_its_first_number_that_is_not_zero(self):
        self.assertTrue(runner_pins.satisfies("1.9.9", "^1.2.3"))
        self.assertFalse(runner_pins.satisfies("2.0.0", "^1.2.3"))
        self.assertFalse(runner_pins.satisfies("0.36.0", "^0.35.0"))
        self.assertTrue(runner_pins.satisfies("0.0.3", "^0.0.3"))
        self.assertFalse(runner_pins.satisfies("0.0.4", "^0.0.3"))

    def test_a_wildcard_takes_every_release_under_its_written_numbers(self):
        self.assertTrue(runner_pins.satisfies("5.0.9", "5.0.*"))
        self.assertFalse(runner_pins.satisfies("5.1.0", "5.0.*"))

    def test_any_alternative_each_of_its_terms_spaced_or_commaed(self):
        constraint = "<12.5.8 || >=12.5.21 <12.5.22 || >=13.1.5,<13.1.6"
        self.assertTrue(runner_pins.satisfies("12.5.7", constraint))
        self.assertTrue(runner_pins.satisfies("12.5.21", constraint))
        self.assertTrue(runner_pins.satisfies("13.1.5", constraint))
        self.assertFalse(runner_pins.satisfies("12.5.20", constraint))
        self.assertFalse(runner_pins.satisfies("13.3.0", constraint))

    def test_a_term_it_cannot_read_is_refused_rather_than_guessed(self):
        with self.assertRaises(ValueError):
            runner_pins.satisfies("5.0.2", "dev-main")


class Behind(unittest.TestCase):
    def test_a_conflict_refuses_the_release_it_meets_and_names_its_upper_bound_raised(self):
        found = runner_pins.behind({"pestphp/pest-plugin-mutate": "5.0.3"}, MANIFEST)

        self.assertEqual([item["rule"] for item in found], ["refuses-a-passing-release"])
        self.assertIn('Write "pestphp/pest-plugin-mutate": "<5.0.2 || >5.0.3" in the conflict of composer.json', found[0]["detail"])
        self.assertIn("tests/Contract/Runner/fixture/composer.json", found[0]["detail"])

    def test_a_requirement_refuses_the_release_it_does_not_meet_and_names_it_widened_by_its_minor(self):
        found = runner_pins.behind({"infection/infection": "0.36.1"}, MANIFEST)

        self.assertIn('Write "infection/infection": "~0.35.0 || ~0.36.0" in the require-dev of composer.json', found[0]["detail"])
        self.assertIn("infection-fixture/phpunit-12/composer.json", found[0]["detail"])

    def test_nothing_where_composer_json_takes_every_release_or_lists_none(self):
        self.assertEqual(runner_pins.behind({"pestphp/pest-plugin-mutate": "5.0.2", "infection/infection": "0.35.6", "pestphp/pest": "5.0.0"}, MANIFEST), [])
        self.assertEqual(runner_pins.behind({"pestphp/pest-plugin-mutate": "5.0.3"}, {}), [])

    def test_a_conflict_it_cannot_raise_says_what_to_change(self):
        found = runner_pins.behind({"pestphp/pest-plugin-mutate": "5.1.0"}, {"conflict": {"pestphp/pest-plugin-mutate": ">=5.1"}})

        self.assertIn("so it takes 5.1.0", found[0]["detail"])


class Releases(unittest.TestCase):
    def test_each_package_composer_lists_in_either_shape_of_installed_json(self):
        listed = [{"name": "infection/infection", "version": "0.35.6"}]

        self.assertEqual(runner_pins.releases(json.dumps({"packages": listed})), {"infection/infection": "0.35.6"})
        self.assertEqual(runner_pins.releases(json.dumps(listed)), {"infection/infection": "0.35.6"})


if __name__ == "__main__":
    unittest.main()
