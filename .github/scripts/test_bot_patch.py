"""A fix read, held to the head, and cut into suggestions: python3 .github/scripts/unit.py"""
import unittest

import bot_patch

HEAD = "a\nb\nc\nd\ne\nf\n"

PATCH = """diff --git a/src/A.php b/src/A.php
index 1..2 100644
--- a/src/A.php
+++ b/src/A.php
@@ -1,3 +1,3 @@
 a
-b
+B
 c
@@ -5,2 +5,3 @@
 e
+E
 f
"""


class Parse(unittest.TestCase):
    def test_it_reads_each_file_and_its_hunks(self):
        files = bot_patch.parse(PATCH)

        self.assertEqual([patch.path for patch in files], ["src/A.php"])
        self.assertEqual(files[0].hunks[0], bot_patch.Hunk(1, (" a", "-b", "+B", " c")))
        self.assertEqual(files[0].hunks[1].old, ["e", "f"])
        self.assertEqual(files[0].hunks[1].new, ["e", "E", "f"])

    def test_a_hunk_header_without_counts_counts_one(self):
        files = bot_patch.parse("diff --git a/x b/x\n@@ -2 +2 @@\n-b\n+B\n")

        self.assertEqual(files[0].hunks[0], bot_patch.Hunk(2, ("-b", "+B")))

    def test_two_files_are_read_one_after_the_other(self):
        text = "diff --git a/x b/x\n@@ -1 +1 @@\n-a\n+b\ndiff --git a/y b/y\n--- a/y\n+++ b/y\n@@ -1 +1 @@\n-c\n+d"

        self.assertEqual([patch.path for patch in bot_patch.parse(text)], ["x", "y"])

    def test_an_empty_patch_changes_nothing(self):
        self.assertEqual(bot_patch.parse(""), [])

    def test_it_refuses_what_is_more_than_a_change_of_lines(self):
        refused = {
            "a rename": "diff --git a/x b/y\n@@ -1 +1 @@\n-a\n+b\n",
            "a new file": "diff --git a/x b/x\nnew file mode 100644\n--- /dev/null\n+++ b/x\n@@ -0,0 +1 @@\n+a\n",
            "a mode": "diff --git a/x b/x\nold mode 100644\nnew mode 100755\n",
            "a binary": "diff --git a/x b/x\nindex 1..2\nBinary files a/x and b/x differ\n",
            "an escape": "diff --git a/../x b/../x\n@@ -1 +1 @@\n-a\n+b\n",
            "an absolute path": "diff --git a//etc/x b//etc/x\n@@ -1 +1 @@\n-a\n+b\n",
            "a strange name": "diff --git a/x y b/x y\n@@ -1 +1 @@\n-a\n+b\n",
            "no header": "@@ -1 +1 @@\n-a\n+b\n",
            "a short hunk": "diff --git a/x b/x\n@@ -1,2 +1,2 @@\n-a\n+b\n",
            "a hunk cut by another line": "diff --git a/x b/x\n@@ -1,2 +1,2 @@\n-a\nnot a line\n",
            "a bad hunk header": "diff --git a/x b/x\n@@ nonsense @@\n-a\n",
            "a final newline added": "diff --git a/x b/x\n@@ -1 +1 @@\n-a\n\\ No newline at end of file\n+a\n",
            "a final newline after the hunk": "diff --git a/x b/x\n@@ -1 +1 @@\n-a\n+b\n\\ No newline at end of file\n",
        }
        for why, text in refused.items():
            with self.subTest(why), self.assertRaises(bot_patch.Refused):
                bot_patch.parse(text)


class Apply(unittest.TestCase):
    def test_every_hunk_is_applied_where_it_matches(self):
        self.assertEqual(bot_patch.apply(bot_patch.parse(PATCH)[0], HEAD), "a\nB\nc\nd\ne\nE\nf\n")

    def test_a_hunk_the_head_does_not_hold_is_refused(self):
        with self.assertRaises(bot_patch.Refused):
            bot_patch.apply(bot_patch.parse(PATCH)[0], "a\nx\nc\nd\ne\nf\n")

    def test_hunks_out_of_order_are_refused(self):
        backwards = bot_patch.FilePatch("x", tuple(reversed(bot_patch.parse(PATCH)[0].hunks)))

        with self.assertRaises(bot_patch.Refused):
            bot_patch.apply(backwards, HEAD)

    def test_an_insertion_into_an_empty_file_starts_it(self):
        patch = bot_patch.FilePatch("x", (bot_patch.Hunk(0, ("+a",)),))

        self.assertEqual(bot_patch.apply(patch, ""), "a\n")


class Suggestions(unittest.TestCase):
    def test_each_run_of_changes_becomes_the_head_lines_it_replaces(self):
        found = bot_patch.suggestions(bot_patch.parse(PATCH)[0], HEAD)

        self.assertEqual(found, [
            bot_patch.Suggestion("src/A.php", 2, 2, ("B",)),
            bot_patch.Suggestion("src/A.php", 5, 5, ("e", "E")),
        ])

    def test_a_run_of_several_lines_spans_them(self):
        patch = bot_patch.parse("diff --git a/x b/x\n@@ -1,3 +1,2 @@\n-a\n-b\n+ab\n c\n")[0]

        self.assertEqual(bot_patch.suggestions(patch, "a\nb\nc\n"), [bot_patch.Suggestion("x", 1, 2, ("ab",))])

    def test_an_insertion_at_the_top_has_no_line_above_and_is_left_to_the_patch(self):
        patch = bot_patch.parse("diff --git a/x b/x\n@@ -1,1 +1,2 @@\n+top\n a\n")[0]

        self.assertEqual(bot_patch.suggestions(patch, "a\n"), [])

    def test_a_hunk_the_head_does_not_hold_suggests_nothing(self):
        self.assertEqual(bot_patch.suggestions(bot_patch.parse(PATCH)[0], "z\n"), [])


class Ranges(unittest.TestCase):
    def test_the_diff_shows_the_head_lines_of_its_hunks(self):
        diff = "@@ -1,3 +1,4 @@\n a\n+b\n c\n d\n@@ -20 +21 @@\n-x\n+y\n@@ -30,2 +32,0 @@\n-p\n-q"

        self.assertEqual(bot_patch.diff_ranges(diff), [(1, 4), (21, 21)])

    def test_a_suggestion_is_inside_where_one_hunk_holds_all_its_lines(self):
        ranges = [(1, 4), (10, 12)]

        self.assertTrue(bot_patch.inside(bot_patch.Suggestion("x", 2, 4, ()), ranges))
        self.assertFalse(bot_patch.inside(bot_patch.Suggestion("x", 4, 10, ()), ranges))
        self.assertFalse(bot_patch.inside(bot_patch.Suggestion("x", 5, 5, ()), ranges))


class Render(unittest.TestCase):
    def test_a_file_patch_is_written_back_as_git_would(self):
        rendered = bot_patch.render(bot_patch.parse(PATCH)[0])

        self.assertEqual(rendered, "--- a/src/A.php\n+++ b/src/A.php\n@@ -1,3 +1,3 @@\n a\n-b\n+B\n c\n@@ -5,2 +5,3 @@\n e\n+E\n f")


class SafePath(unittest.TestCase):
    def test_only_a_plain_relative_path_is_safe(self):
        for path in ("src/A.php", ".github/x.yml", "a-b_c+d.md"):
            self.assertTrue(bot_patch.safe_path(path), path)
        for path in ("", "/a", "../a", "a/../b", "a/..", "a b", "a`b", "[x](y)", "@user", "a\nb", "x" * 301):
            self.assertFalse(bot_patch.safe_path(path), path)


if __name__ == "__main__":
    unittest.main()
