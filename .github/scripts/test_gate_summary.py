"""The deciding half of gate_summary.py: python3 -m unittest discover .github/scripts"""
import unittest

import gate_summary

CHECKS = {
    "check": "checks",
    "checks": "The manifest, the formatting, the static analysis and the dependencies.",
    "reproduce": "composer ci",
    "fix": "composer lint:fix",
}


class Summary(unittest.TestCase):
    def test_it_says_what_the_gate_checks_and_the_commands_that_reproduce_and_fix_it(self):
        text = gate_summary.summary(CHECKS)

        self.assertTrue(text.startswith("### `checks` failed\n\nThe manifest, the formatting"))
        self.assertIn("```sh\ncomposer ci\n```", text)
        self.assertIn("Fix it with:\n\n```sh\ncomposer lint:fix\n```", text)

    def test_it_names_no_fix_where_there_is_none_and_links_the_troubleshooting_section(self):
        text = gate_summary.summary({**CHECKS, "fix": "", "troubleshooting": "no-coverage-driver"})

        self.assertNotIn("Fix it with", text)
        self.assertIn(
            "[no-coverage-driver](https://github.com/nightworksio/php-mutation-gate/blob/main/.docs/guide/troubleshooting.md#no-coverage-driver)",
            text,
        )


class Annotation(unittest.TestCase):
    def test_it_is_an_error_titled_with_the_gate_that_names_the_commands(self):
        self.assertEqual(
            gate_summary.annotation(CHECKS),
            "::error title=checks failed::The manifest, the formatting, the static analysis and the dependencies. "
            "Reproduce it with: composer ci. Fix it with: composer lint:fix",
        )

    def test_it_escapes_what_a_workflow_command_would_read_as_its_own(self):
        text = gate_summary.annotation({**CHECKS, "check": "hygiene / typos: a, b", "checks": "100%\nsure.", "fix": ""})

        self.assertEqual(text, "::error title=hygiene / typos%3A a%2C b failed::100%25%0Asure. Reproduce it with: composer ci.")


if __name__ == "__main__":
    unittest.main()
