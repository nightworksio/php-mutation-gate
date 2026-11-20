"""progress_watch.py's reading of the process tree and of progress: python3 -m unittest discover .github/scripts"""
import os
import subprocess
import sys
import tempfile
import unittest

import progress_watch

GATE, WORKER, OTHER, MUTANT = 10, 11, 12, 13


class Tree(unittest.TestCase):
    def test_it_reads_the_parent_the_name_and_the_ticks_used_even_of_a_name_with_spaces_and_parentheses(self):
        stat = "4242 (php (warm) 1) S 4200 4242 4200 0 -1 4194304 100 0 0 0 731 22 0 0 20 0 1 0 5 0 0"

        self.assertEqual(progress_watch.stat_of(stat), (4200, "php (warm) 1", 753))

    def test_it_takes_every_process_under_the_root_and_none_beside_it(self):
        parents = {10: 1, 11: 10, 12: 11, 13: 11, 20: 1, 21: 20}

        self.assertEqual(progress_watch.tree(parents, 10), {10, 11, 12, 13})

    def test_the_processes_with_no_child_are_the_ones_doing_the_work(self):
        parents = {10: 1, 11: 10, 12: 11, 13: 11}

        self.assertEqual(progress_watch.leaves(parents, {10, 11, 12, 13}), {12, 13})


class Progress(unittest.TestCase):
    def test_a_worker_blocked_on_its_parents_pipe_while_the_parent_works_is_progress(self):
        before = {GATE: 1000, WORKER: 500}
        after = {GATE: 1090, WORKER: 500}

        self.assertTrue(progress_watch.progressed(before, after, {WORKER}, 1.0, 100))

    def test_the_gate_waiting_on_a_child_that_works_is_progress(self):
        before = {GATE: 1000, WORKER: 500, MUTANT: 20}
        after = {GATE: 1000, WORKER: 500, MUTANT: 110}

        self.assertTrue(progress_watch.progressed(before, after, {MUTANT}, 1.0, 100))

    def test_a_test_asleep_while_another_works_is_progress(self):
        before = {GATE: 1000, WORKER: 500, OTHER: 400, MUTANT: 20, 14: 30}
        after = {GATE: 1000, WORKER: 500, OTHER: 400, MUTANT: 20, 14: 120}

        self.assertTrue(progress_watch.progressed(before, after, {MUTANT, 14}, 1.0, 100))

    def test_a_process_that_starts_or_ends_is_progress(self):
        self.assertTrue(progress_watch.progressed({GATE: 1000}, {GATE: 1000, MUTANT: 0}, {MUTANT}, 1.0, 100))
        self.assertTrue(progress_watch.progressed({GATE: 1000, MUTANT: 5}, {GATE: 1000}, {GATE}, 1.0, 100))

    def test_workers_that_never_end_while_the_gate_only_polls_them_are_no_progress(self):
        before = {GATE: 1000, WORKER: 500, OTHER: 400}
        after = {GATE: 1009, WORKER: 500, OTHER: 400}

        self.assertFalse(progress_watch.progressed(before, after, {WORKER, OTHER}, 1.0, 100))

    def test_a_parent_working_past_its_polling_share_over_a_longer_interval_is_progress(self):
        before = {GATE: 1000, WORKER: 500}

        self.assertFalse(progress_watch.progressed(before, {GATE: 1020, WORKER: 500}, {WORKER}, 2.0, 100))
        self.assertTrue(progress_watch.progressed(before, {GATE: 1021, WORKER: 500}, {WORKER}, 2.0, 100))


@unittest.skipUnless(os.path.exists("/proc/self/stat"), "it reads /proc, which only Linux has")
class Watching(unittest.TestCase):
    def test_it_stops_a_tree_where_every_process_waits_and_says_so(self):
        with tempfile.TemporaryDirectory() as directory:
            report = os.path.join(directory, "stuck.txt")
            asleep = subprocess.Popen(["sleep", "30"])
            progress_watch.main(asleep.pid, 2, report)

            self.assertEqual(asleep.wait(timeout=10), -9)
            with open(report, encoding="utf-8") as said:
                self.assertTrue(said.read().startswith("no progress for 2 s: no process used CPU, of sleep (pid "))

    def test_it_lets_a_tree_that_works_run_to_its_end(self):
        with tempfile.TemporaryDirectory() as directory:
            report = os.path.join(directory, "stuck.txt")
            busy = subprocess.Popen([sys.executable, "-c", "import time\nend = time.time() + 4\nwhile time.time() < end: pass"])
            progress_watch.main(busy.pid, 2, report)

            self.assertEqual(busy.wait(timeout=10), 0)
            self.assertFalse(os.path.exists(report))


if __name__ == "__main__":
    unittest.main()
