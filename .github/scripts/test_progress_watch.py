"""progress_watch.py's reading of the process tree: python3 -m unittest discover .github/scripts"""
import unittest

import progress_watch


class Watch(unittest.TestCase):
    def test_it_reads_the_parent_the_name_and_the_ticks_used_even_of_a_name_with_spaces_and_parentheses(self):
        stat = "4242 (php (warm) 1) S 4200 4242 4200 0 -1 4194304 100 0 0 0 731 22 0 0 20 0 1 0 5 0 0"

        self.assertEqual(progress_watch.stat_of(stat), (4200, "php (warm) 1", 753))

    def test_it_takes_every_process_under_the_root_and_none_beside_it(self):
        parents = {10: 1, 11: 10, 12: 11, 13: 11, 20: 1, 21: 20}

        self.assertEqual(progress_watch.tree(parents, 10), {10, 11, 12, 13})

    def test_it_watches_only_the_processes_that_have_no_child(self):
        parents = {10: 1, 11: 10, 12: 11, 13: 11}

        self.assertEqual(progress_watch.leaves(parents, {10, 11, 12, 13}), {12, 13})

    def test_a_process_is_stuck_once_its_ticks_have_not_moved_for_the_bound_and_not_before(self):
        since = {12: 100.0, 13: 130.0}

        self.assertIsNone(progress_watch.stalled(since, 129.9, 30))
        self.assertEqual(progress_watch.stalled(since, 130.0, 30), 12)
        self.assertEqual(progress_watch.stalled({13: 130.0}, 160.0, 30), 13)


if __name__ == "__main__":
    unittest.main()
