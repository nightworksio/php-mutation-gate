import contextlib
import io
import tempfile
import unittest
from pathlib import Path

import evidence_overlay


def written(root: Path, files: dict) -> Path:
    for name, text in files.items():
        file = root / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(text)

    return root


class OverlayTest(unittest.TestCase):
    def setUp(self):
        self.scratch = Path(tempfile.mkdtemp())

    def gathered(self, earlier: dict, attempt: dict) -> tuple:
        earlier_dir = written(self.scratch / "earlier", earlier) if earlier is not None else self.scratch / "none-earlier"
        attempt_dir = written(self.scratch / "attempt", attempt) if attempt is not None else self.scratch / "none-attempt"
        out = io.StringIO()

        with contextlib.redirect_stdout(out):
            code = evidence_overlay.main(["evidence_overlay.py", str(earlier_dir), str(attempt_dir), str(self.scratch / "gathered")])

        gathered = self.scratch / "gathered"
        files = {
            file.relative_to(gathered).as_posix(): file.read_text()
            for file in sorted(gathered.rglob("*"))
            if file.is_file()
        } if gathered.is_dir() else {}

        return code, files, out.getvalue()

    def test_a_job_run_again_speaks_for_itself_and_one_not_run_again_keeps_what_it_said(self):
        code, files, _ = self.gathered(
            {"tests.json": "red", "rules.json": "green", "raw/tests.xml": "old"},
            {"tests.json": "green", "raw/tests.xml": "new"},
        )

        self.assertEqual(code, 0)
        self.assertEqual(files, {"tests.json": "green", "rules.json": "green", "raw/tests.xml": "new"})

    def test_the_first_attempt_gathers_what_its_jobs_left(self):
        self.assertEqual(self.gathered(None, {"tests.json": "green"})[:2], (0, {"tests.json": "green"}))

    def test_an_attempt_that_ran_no_job_that_leaves_evidence_keeps_the_earlier_attempts(self):
        self.assertEqual(self.gathered({"tests.json": "green"}, None)[:2], (0, {"tests.json": "green"}))

    def test_a_run_that_left_no_evidence_at_all_fails_saying_so(self):
        code, files, said = self.gathered(None, {})

        self.assertEqual((code, files), (1, {}))
        self.assertEqual(said.strip(), f"::error::{evidence_overlay.NONE}")

    def test_it_takes_three_directories(self):
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(evidence_overlay.main(["evidence_overlay.py", "earlier"]), 2)


if __name__ == "__main__":
    unittest.main()
