"""The heading index `/docs` searches: python3 -m unittest discover .github/scripts"""
import tempfile
import unittest
from pathlib import Path

import bot_docs

PAGE = """# Troubleshooting

## The floor rose

Text.

```markdown
## Not a heading
```

## The floor rose

### `stub` and [the hints](hints.md) *explained*
"""


class Tokens(unittest.TestCase):
    def test_what_was_asked_is_cut_to_lowercase_letters_and_digits(self):
        self.assertEqual(bot_docs.tokens("Why <b>FLOOR</b> $(id); rm -rf / 0019"), ["why", "b", "floor", "id", "rm", "rf", "0019"])

    def test_it_keeps_each_token_once_and_at_most_eight_short_ones(self):
        self.assertEqual(bot_docs.tokens("a a b"), ["a", "b"])
        self.assertEqual(len(bot_docs.tokens(" ".join(f"w{number}" for number in range(20)))), bot_docs.MAX_TOKENS)
        self.assertEqual(bot_docs.tokens("x" * 33 + " y"), ["y"])


class Headings(unittest.TestCase):
    def test_every_heading_outside_a_fence_with_the_anchor_github_gives_it(self):
        self.assertEqual(bot_docs.headings(PAGE), [
            ("Troubleshooting", "troubleshooting"),
            ("The floor rose", "the-floor-rose"),
            ("The floor rose", "the-floor-rose-1"),
            ("stub and the hints *explained*", "stub-and-the-hints-explained"),
        ])


class Search(unittest.TestCase):
    ENTRIES = [
        (".docs/guide/troubleshooting.md", "Troubleshooting", "troubleshooting"),
        (".docs/guide/troubleshooting.md", "The floor rose", "the-floor-rose"),
        (".docs/guide/troubleshooting.md", "The floor rose after a merge", "the-floor-rose-after-a-merge"),
        (".docs/guide/setup.md", "Install", "install"),
        (".docs/guide/setup.md", "Floor", "floor"),
    ]

    def test_the_headings_most_tokens_name_come_first_and_three_at_most(self):
        found = bot_docs.search(self.ENTRIES, ["floor", "merge"])

        self.assertEqual([slug for _, _, slug in found], ["the-floor-rose-after-a-merge", "the-floor-rose", "floor"])

    def test_the_page_name_counts_as_a_word_of_its_headings(self):
        found = bot_docs.search(self.ENTRIES, ["setup"])

        self.assertEqual([slug for _, _, slug in found], ["install", "floor"])

    def test_nothing_named_finds_nothing(self):
        self.assertEqual(bot_docs.search(self.ENTRIES, []), [])
        self.assertEqual(bot_docs.search(self.ENTRIES, ["zzz"]), [])


class Index(unittest.TestCase):
    def test_it_reads_every_page_under_the_directory_by_its_path(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / ".docs"
            (root / "guide").mkdir(parents=True)
            (root / "guide" / "a.md").write_text("# A\n", encoding="utf-8")
            (root / "b.txt").write_text("# B\n", encoding="utf-8")

            self.assertEqual(bot_docs.index(root), [(".docs/guide/a.md", "A", "a")])


if __name__ == "__main__":
    unittest.main()
