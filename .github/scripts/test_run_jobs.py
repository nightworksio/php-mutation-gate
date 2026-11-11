"""run_jobs.py, and the steps of gate_action.py that read the run's jobs: what they took, whether a watched job
failed, and how each shard job ended: python3 -m unittest discover .github/scripts"""
from datetime import datetime, timezone
import json
import os
import subprocess
import sys
import tempfile
import unittest

import action_script  # puts the action's scripts on the import path
import run_jobs


class Stale(unittest.TestCase):
    def test_reads_each_shard_job_s_latest_attempt_by_its_number_under_the_calling_job_s_name(self):
        jobs = [
            {"name": "mutation / shard 3 (src, part 3 of 12)", "run_attempt": 2},
            {"name": "mutation / shard 3 (src, part 3 of 12)", "run_attempt": 1},
            {"name": "shard 12 (plugins/default/src)", "run_attempt": 1},
            {"name": "mutation / verdict", "run_attempt": 2},
            {"name": "mutation / reshard 4 (src)", "run_attempt": 2},
            {"name": "mutation / shard 5 (src)", "run_attempt": "2"},
            "mutation / shard 6 (src)",
        ]

        self.assertEqual(run_jobs.shard_attempts(jobs), {"3": 2, "12": 1})

    def test_counts_no_job_a_re_run_kept_from_an_earlier_attempt_which_started_before_it_was_created(self):
        jobs = [
            {
                "name": "mutation / shard 1 (src, part 1 of 20)",
                "run_attempt": 2,
                "created_at": "2026-11-03T05:15:02Z",
                "started_at": "2026-11-03T04:17:08Z",
            },
            {
                "name": "mutation / shard 4 (src, part 4 of 20)",
                "run_attempt": 2,
                "created_at": "2026-11-03T05:15:03Z",
                "started_at": "2026-11-03T05:15:07Z",
            },
        ]

        self.assertEqual(run_jobs.shard_attempts(jobs), {"4": 2})
        self.assertEqual(run_jobs.stale({"1": 1, "4": 2}, run_jobs.shard_attempts(jobs)), [])

    def test_names_each_shard_whose_result_an_attempt_before_its_job_s_latest_run_wrote(self):
        written = {"10": 1, "2": 1, "3": 2, "4": 0, "7": 1}
        latest = {"10": 2, "2": 1, "3": 2, "4": 1}

        self.assertEqual(run_jobs.stale(written, latest), ["4", "10"])

    def test_says_which_attempt_ran_the_shard_again_and_which_wrote_the_result_left(self):
        why = run_jobs.stale_text("4", 1, 2)

        self.assertIn("Shard 4 ran again in attempt 2 and wrote no result", why)
        self.assertIn("the result attempt 1 wrote is not judged", why)


def shard_job(number: int, conclusion: str, completed: str, **more) -> dict:
    """A shard job of attempt 1 as GitHub lists it, with these fields beside."""
    return {
        "id": 100 + number,
        "name": f"mutation / shard {number} (src, part {number} of 12)",
        "run_attempt": 1,
        "created_at": "2026-11-08T18:39:30Z",
        "started_at": "2026-11-08T18:39:36Z",
        "completed_at": completed,
        "conclusion": conclusion,
        "html_url": f"https://github.com/octo/gate/actions/runs/1/job/{100 + number}",
        "steps": [{"name": "Set up job", "conclusion": "success"}],
    } | more


class Ended(unittest.TestCase):
    def test_names_a_runner_shut_down_and_a_shard_the_matrix_cancelled_once_another_failed(self):
        shutdown = "##[error]The runner has received a shutdown signal. This can happen when the runner service is stopped"
        lost = [{"name": "Run ./.mutation-gate/workflow", "conclusion": None}]
        jobs = [
            shard_job(1, "cancelled", "2026-11-08T19:18:47Z"),
            shard_job(3, "success", "2026-11-08T20:02:34Z"),
            shard_job(7, "cancelled", "2026-11-08T20:19:52Z"),
            shard_job(10, "failure", "2026-11-08T20:19:38Z", steps=lost),
            {"name": "mutation / verdict", "run_attempt": 1, "conclusion": "failure"},
        ]

        ended = run_jobs.endings(jobs, {101: shutdown, 107: "##[error]The operation was canceled."})

        self.assertEqual(
            ended,
            {
                "1": {"how": "shutdown", "job": "https://github.com/octo/gate/actions/runs/1/job/101"},
                "7": {"how": "cancelled", "job": "https://github.com/octo/gate/actions/runs/1/job/107", "after": 10},
                "10": {"how": "lost", "job": "https://github.com/octo/gate/actions/runs/1/job/110"},
            },
        )

    def test_a_cancel_before_any_shard_failed_names_none_and_a_failure_whose_steps_all_ended_failed(self):
        jobs = [
            shard_job(2, "cancelled", "2026-11-08T19:00:00Z"),
            shard_job(4, "failure", "2026-11-08T19:30:00Z"),
            shard_job(5, "failure", "2026-11-08T19:40:00Z"),
        ]

        ended = run_jobs.endings(jobs, {})

        self.assertEqual(ended["2"], {"how": "cancelled", "job": jobs[0]["html_url"]})
        self.assertEqual(ended["4"]["how"], "failed")

    def test_a_job_past_its_time_limit_timed_out(self):
        said = "##[error]The job has exceeded the maximum execution time of 6h0m0s"

        self.assertEqual(run_jobs.ending(shard_job(1, "cancelled", ""), said), "timed-out")
        self.assertEqual(run_jobs.ending(shard_job(1, "timed_out", ""), ""), "timed-out")

    def test_reads_only_each_shard_s_latest_run_and_not_a_run_a_re_run_kept(self):
        earlier = shard_job(6, "cancelled", "2026-11-08T19:00:00Z")
        again = shard_job(6, "success", "2026-11-08T21:00:00Z", run_attempt=2, id=206)
        carried = shard_job(8, "cancelled", "2026-11-08T19:10:00Z", run_attempt=2, created_at="2026-11-08T21:00:00Z")

        self.assertEqual(run_jobs.endings([earlier, again, carried], {}), {})

    def test_writes_how_each_shard_without_a_result_ended_beside_where_its_result_would_be(self):
        with tempfile.TemporaryDirectory() as directory:
            results, errors = os.path.join(directory, "results"), os.path.join(directory, "errors")
            os.makedirs(results)
            os.makedirs(errors)
            jobs = os.path.join(directory, "jobs.json")
            with open(jobs, "w", encoding="utf-8") as file:
                json.dump([shard_job(1, "cancelled", "a"), shard_job(2, "failure", "b")], file)
            with open(os.path.join(errors, "101.txt"), "w", encoding="utf-8") as file:
                file.write("##[error]The runner has received a shutdown signal.")
            with open(os.path.join(results, "2.json"), "w", encoding="utf-8") as file:
                file.write("{}")

            subprocess.run(
                [sys.executable, action_script.PATH, "ended"],
                env={**os.environ, "JOBS": jobs, "RESULTS": results, "ERRORS": errors},
                check=True,
            )

            with open(os.path.join(results, "1.ended.json"), encoding="utf-8") as file:
                self.assertEqual(json.load(file)["how"], "shutdown")
            self.assertFalse(os.path.exists(os.path.join(results, "2.ended.json")))


class Watch(unittest.TestCase):
    def test_names_each_watched_job_once_a_line_and_leaves_blank_lines_out(self):
        self.assertEqual(run_jobs.watched_names("checks\n\n  rules  \nscripts\n"), ["checks", "rules", "scripts"])
        self.assertEqual(run_jobs.watched_names("\n \n"), [])

    def test_names_the_first_watched_job_that_failed_in_what_an_artifact_s_name_may_hold(self):
        jobs = [
            {"name": "lint", "conclusion": "failure"},
            {"name": "checks", "conclusion": "success"},
            {"name": "tests: unit/php", "conclusion": "failure"},
        ]

        self.assertEqual(run_jobs.watched_failure(jobs, ["checks", "tests: unit/php"]), "tests- unit-php")
        self.assertEqual(run_jobs.watched_failure(jobs, ["checks"]), "")

    def test_watches_until_every_watched_job_ended_and_waits_a_while_for_one_the_run_does_not_list(self):
        jobs = [{"name": "checks", "status": "completed"}, {"name": "rules", "status": "in_progress"}]

        self.assertFalse(run_jobs.watch_settled(jobs, ["checks", "rules"], unlisted_waited=True))
        self.assertTrue(run_jobs.watch_settled(jobs, ["checks"], unlisted_waited=False))
        self.assertFalse(run_jobs.watch_settled(jobs, ["checks", "scripts"], unlisted_waited=False))
        self.assertTrue(run_jobs.watch_settled(jobs, ["checks", "scripts"], unlisted_waited=True))


class RunTimesStep(unittest.TestCase):
    def test_writes_what_the_listed_jobs_took_and_nothing_where_it_lists_none(self):
        with tempfile.TemporaryDirectory() as directory:
            jobs = os.path.join(directory, "jobs.json")
            times = os.path.join(directory, "times.json")
            with open(jobs, "w", encoding="utf-8") as file:
                json.dump([{"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:00:30Z"}], file)
            environment = {**os.environ, "JOBS": jobs, "RUN_TIMES": times, "GITHUB_OUTPUT": os.path.join(directory, "outputs")}

            ran = subprocess.run([sys.executable, action_script.PATH, "run-times"], env=environment, capture_output=True, text=True, check=False)
            written = os.path.isfile(times)
            os.remove(times)
            none = subprocess.run([sys.executable, action_script.PATH, "run-times"], env={**environment, "JOBS": os.path.join(directory, "gone.json")}, capture_output=True, text=True, check=False)

            self.assertEqual((0, True), (ran.returncode, written), ran.stderr)
            self.assertEqual((0, False), (none.returncode, os.path.isfile(times)), none.stderr)


class RunTimes(unittest.TestCase):
    NOW = datetime(2026, 11, 7, 12, 0, 0, tzinfo=timezone.utc)

    def test_sums_each_job_and_spans_the_run_counting_a_running_job_up_to_now(self):
        jobs = [
            {"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:10:00Z"},
            {"started_at": "2026-11-07T11:05:00Z", "completed_at": "2026-11-07T11:20:00Z"},
            {"started_at": "2026-11-07T11:59:00Z", "completed_at": None},
        ]

        self.assertEqual(
            {"wallSeconds": 3600.0, "runnerSeconds": 600.0 + 900.0 + 60.0},
            run_jobs.run_times(jobs, self.NOW),
        )

    def test_says_when_the_plan_s_job_began_and_what_it_took(self):
        jobs = [
            {"name": "mutation / plan", "started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:02:00Z"},
            {"name": "mutation / explain the plan", "started_at": "2026-11-07T11:01:00Z", "completed_at": "2026-11-07T11:02:00Z"},
        ]

        self.assertEqual(
            {"at": "2026-11-07T11:00:00Z", "seconds": 120.0},
            run_jobs.run_times(jobs, self.NOW)["plan"],
        )

    def test_leaves_out_a_job_that_never_started_and_says_nothing_of_a_run_with_none(self):
        started = {"started_at": "2026-11-07T11:00:00Z", "completed_at": "2026-11-07T11:00:30Z"}

        self.assertEqual(
            {"wallSeconds": 30.0, "runnerSeconds": 30.0},
            run_jobs.run_times([started, {"started_at": None}, {"started_at": "not a time"}], self.NOW),
        )
        self.assertIsNone(run_jobs.run_times([{"started_at": None}], self.NOW))
        self.assertIsNone(run_jobs.run_times([], self.NOW))


if __name__ == "__main__":
    unittest.main()


# What `git ls-remote --tags` lists: a release tag, annotated, with the commit
# it points at; the line's tag at the same commit; and a release at another.
