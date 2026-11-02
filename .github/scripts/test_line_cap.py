#!/usr/bin/env python3
"""The cap on a tracked file's length, against real git trees.

Each case builds a repository in a temporary directory, commits what it needs and
runs the check over it, so what is counted is what `git ls-files` lists and not a
list the test made up.

Stdlib unittest, no dependencies.
Run:  python3 -m unittest discover --start-directory .github/scripts
"""

from __future__ import annotations

import io
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

import line_cap as cap


def git(root: pathlib.Path, *args: str) -> None:
    subprocess.run(
        ["git", "-C", str(root), *args],
        check=True,
        capture_output=True,
        env={**os.environ, "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_NOSYSTEM": "1"},
    )


class ATree(unittest.TestCase):
    """A repository, and what the check says about it."""

    def setUp(self):
        self.root = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
        git(self.root, "init", "-q")

    def put(self, path: str, lines: int, *, tracked: bool = True) -> None:
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("x\n" * lines, encoding="utf-8")
        if tracked:
            git(self.root, "add", path)

    def run_check(self) -> tuple[int, str]:
        out = io.StringIO()
        code = cap.main(["--root", str(self.root)], out=out)
        return code, out.getvalue()

    def test_a_file_at_the_cap_passes(self):
        self.put("src/at.py", cap.CAP)

        code, said = self.run_check()

        self.assertEqual(code, 0, said)
        self.assertIn("none over 1000 lines", said)

    def test_a_file_one_line_over_is_refused_naming_it_its_count_and_the_cap(self):
        self.put("src/at.py", cap.CAP)
        self.put("docs/long.md", cap.CAP + 1)

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn(
            "::error file=docs/long.md::docs/long.md is 1001 lines, over the cap of 1000.",
            said,
        )
        self.assertNotIn("src/at.py", said)
        self.assertIn("1 of 2 tracked file(s) are over 1000 lines.", said)

    def test_generated_output_is_counted_like_anything_else(self):
        self.put("contract/generated.json", cap.CAP + 5)

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("contract/generated.json is 1005 lines", said)

    def test_offenders_are_named_longest_first(self):
        self.put("a.txt", cap.CAP + 1)
        self.put("b.txt", cap.CAP + 9)

        _, said = self.run_check()

        self.assertLess(said.index("b.txt is 1009"), said.index("a.txt is 1001"))

    def test_lockfiles_images_and_fonts_are_not_counted(self):
        for path in (
            "Cargo.lock",
            "fuzz/Cargo.lock",
            "composer.lock",
            "uv.lock",
            "web/package-lock.json",
            "brand/logo.svg",
            "brand/LOGO.PNG",
            "fonts/face.woff2",
        ):
            self.put(path, cap.CAP + 1)
        self.put("small.txt", 1)

        code, said = self.run_check()

        self.assertEqual(code, 0, said)

    def test_a_file_named_like_a_lockfile_but_not_one_is_counted(self):
        self.put("package-lock.json.md", cap.CAP + 1)

        code, said = self.run_check()

        self.assertEqual(code, 1, said)

    def test_an_untracked_file_is_not_counted(self):
        self.put("small.txt", 1)
        self.put("scratch.log", cap.CAP + 1, tracked=False)

        code, said = self.run_check()

        self.assertEqual(code, 0, said)

    def test_a_tree_that_tracks_nothing_has_not_passed(self):
        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("a check that read nothing has not passed", said)

    def test_a_directory_that_is_not_there_is_refused(self):
        out = io.StringIO()
        code = cap.main(["--root", str(self.root / "missing")], out=out)

        self.assertEqual(code, 1)
        self.assertIn("git could not list", out.getvalue())

    def test_a_directory_that_is_not_a_repository_is_refused(self):
        bare = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, bare, ignore_errors=True)
        out = io.StringIO()
        code = cap.main(["--root", str(bare)], out=out)

        self.assertEqual(code, 1)
        self.assertIn("git could not list", out.getvalue())

    def test_a_symbolic_link_is_not_followed_into_its_target(self):
        self.put("small.txt", 1)
        long = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, long, ignore_errors=True)
        (long / "big.txt").write_text("x\n" * (cap.CAP + 1), encoding="utf-8")
        os.symlink(long / "big.txt", self.root / "link.txt")
        git(self.root, "add", "link.txt")

        code, said = self.run_check()

        self.assertEqual(code, 0, said)

    def test_a_deleted_but_still_staged_path_is_skipped(self):
        self.put("small.txt", 1)
        self.put("gone.txt", cap.CAP + 1)
        (self.root / "gone.txt").unlink()

        code, said = self.run_check()

        self.assertEqual(code, 0, said)


class AGeneratedFile(ATree):
    """A file the repository's registry says is generated, and from what."""

    def register(self, path: str, sources: str, *, literal: bool = True) -> None:
        value = sources if literal else "SOURCES"
        registry = self.root / "scripts" / "generated.py"
        registry.parent.mkdir(parents=True, exist_ok=True)
        registry.write_text(
            f"SOURCES = {sources}\n"
            "GENERATED = (\n"
            "    Generated(\n"
            '        generator="scripts/gen.py",\n'
            f'        paths=("{path}",),\n'
            f"        sources={value},\n"
            "    ),\n"
            ")\n",
            encoding="utf-8",
        )
        git(self.root, "add", "scripts/generated.py")

    def test_one_whose_sources_are_within_the_cap_passes(self):
        self.put("gates.yml", cap.CAP + 50)
        self.put("parts/a.yml", 400)
        self.put("parts/b.yml", 600)
        self.register("gates.yml", '("parts/a.yml", "parts/b.yml")')

        code, said = self.run_check()

        self.assertEqual(code, 0, said)

    def test_one_whose_source_is_over_the_cap_is_refused_naming_the_source(self):
        self.put("gates.yml", cap.CAP + 50)
        self.put("parts/a.yml", 400)
        self.put("parts/b.yml", cap.CAP + 2)
        self.register("gates.yml", '("parts/a.yml", "parts/b.yml")')

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn(
            "gates.yml is 1050 lines, over the cap of 1000, and is held to its sources: "
            "it is generated from parts/b.yml, which is 1002 lines.",
            said,
        )
        self.assertNotIn("parts/a.yml", said)
        self.assertIn("parts/b.yml is 1002 lines", said)
        self.assertIn("2 of 4 tracked file(s) are over 1000 lines.", said)

    def test_one_whose_source_is_not_tracked_is_refused_naming_the_source(self):
        self.put("gates.yml", cap.CAP + 50)
        self.put("parts/a.yml", 400, tracked=False)
        self.register("gates.yml", '("parts/a.yml",)')

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("it is generated from parts/a.yml, which is not a tracked file", said)

    def test_one_registered_without_sources_is_counted(self):
        self.put("board.json", cap.CAP + 1)
        self.register("board.json", "()")

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("board.json is 1001 lines, over the cap of 1000. Split it", said)

    def test_an_unregistered_file_over_the_cap_is_still_refused(self):
        self.put("gates.yml", cap.CAP + 50)
        self.put("parts/a.yml", 400)
        self.put("other.yml", cap.CAP + 1)
        self.register("gates.yml", '("parts/a.yml",)')

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("other.yml is 1001 lines", said)
        self.assertNotIn("gates.yml", said)

    def test_sources_not_written_as_a_literal_register_nothing(self):
        self.put("gates.yml", cap.CAP + 50)
        self.put("parts/a.yml", 400)
        self.register("gates.yml", '("parts/a.yml",)', literal=False)

        code, said = self.run_check()

        self.assertEqual(code, 1, said)
        self.assertIn("gates.yml is 1050 lines, over the cap of 1000. Split it", said)


class Counting(unittest.TestCase):
    def test_a_last_line_without_a_newline_is_a_line(self):
        self.assertEqual(cap.lines_in(b"a\nb"), 2)
        self.assertEqual(cap.lines_in(b"a\nb\n"), 2)
        self.assertEqual(cap.lines_in(b""), 0)

    def test_what_is_exempt_is_decided_by_name(self):
        self.assertTrue(cap.exempt("x/Cargo.lock"))
        self.assertTrue(cap.exempt("package-lock.json"))
        self.assertTrue(cap.exempt("a/b.Jpeg"))
        self.assertFalse(cap.exempt("lock"))
        self.assertFalse(cap.exempt("package-lock.json5"))
        self.assertFalse(cap.exempt("stack.toml"))


if __name__ == "__main__":
    unittest.main()
