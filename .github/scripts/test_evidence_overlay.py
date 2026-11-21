import contextlib
import io
import tempfile
import unittest
from pathlib import Path

import evidence_overlay

ACTION = Path(__file__).resolve().parents[1] / "actions" / "evidence" / "action.yml"
STEP = "    - "


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


def steps() -> list:
    """Each step of the evidence action, as its keys and their values, those under `with` by their own keys."""
    found = []

    for line in ACTION.read_text(encoding="utf-8").splitlines():
        if line.startswith(STEP):
            found.append({})
            line = "      " + line[len(STEP):]
        key, _, value = line.strip().partition(": ")
        if found and line.startswith("      ") and value:
            found[-1][key] = value

    return found


class ActionTest(unittest.TestCase):
    def reads(self) -> list:
        return [step for step in steps() if step.get("pattern") == "evidence-*"]

    def test_a_read_github_does_not_serve_is_tried_again_after_a_longer_wait_each_time_and_the_last_fails_the_job(self):
        every = steps()
        reads = self.reads()
        waits = []

        for earlier, later in zip(reads, reads[1:]):
            failed = f"steps.{earlier['id']}.outcome == 'failure'"
            between = every[every.index(earlier) + 1 : every.index(later)]
            self.assertEqual(earlier.get("continue-on-error"), "true")
            self.assertEqual(later.get("if"), failed)
            self.assertEqual([step.get("if") for step in between], [failed])
            waits.append(int(between[0]["run"].removeprefix("sleep ")))

        self.assertNotIn("if", reads[0])
        self.assertNotIn("continue-on-error", reads[-1])
        self.assertEqual(waits, [5, 10, 20])

    def test_a_rerun_of_the_gathering_job_alone_reads_every_job_s_evidence_as_the_first_attempt_left_it(self):
        uses = [step.get("uses", "") for step in steps()]
        names = [step.get("name", "") for step in steps() if "uses" in step]

        self.assertEqual([use for use in uses if "upload-artifact/merge" in use], [])
        self.assertEqual([step for step in steps() if "delete-merged" in step], [])
        self.assertEqual([name for name in names if "run_attempt" in name], [])

    def test_every_try_reads_the_newest_evidence_of_every_job_into_one_place(self):
        tried = {(step["uses"], step["merge-multiple"], step["path"]) for step in self.reads()}

        self.assertEqual(len(self.reads()), 4)
        self.assertEqual(len(tried), 1)
        self.assertEqual(next(iter(tried))[1:], ("true", "${{ runner.temp }}/evidence/attempt"))


if __name__ == "__main__":
    unittest.main()
