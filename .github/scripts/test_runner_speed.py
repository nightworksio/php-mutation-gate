"""The deciding half of runner_speed.py: python3 -m unittest discover .github/scripts"""
import unittest

import runner_speed


class Speed(unittest.TestCase):
    def test_it_takes_the_median_so_one_slow_repeat_moves_nothing(self):
        self.assertEqual(runner_speed.median([1.0, 1.1, 9.0, 0.9, 1.05]), 1.05)

    def test_it_scales_by_how_much_slower_the_runner_is_and_never_below_one(self):
        self.assertEqual(runner_speed.scale(1.5, 1.0), 1.5)
        self.assertEqual(runner_speed.scale(1.0, 1.0), 1.0)
        self.assertEqual(runner_speed.scale(0.5, 1.0), 1.0)

    def test_it_rounds_a_scaled_budget_up_to_whole_seconds(self):
        self.assertEqual(runner_speed.scaled(100, 1.0), 100)
        self.assertEqual(runner_speed.scaled(100, 1.234), 124)
        self.assertEqual(runner_speed.scaled(10, 1.01), 11)

    def test_it_says_the_measured_time_the_reference_the_scale_and_every_budget_both_ways(self):
        said = runner_speed.lines(1.3, 1.0, 1.3, 2.5, {"RUN_SECONDS": 100, "LOOP_SECONDS": 10})

        self.assertEqual(
            said,
            [
                "runner speed: the suite under pcov took 1.30 s, the median of 5; "
                "the reference is 1.00 s, so the scale is 1.30 (at most 2.50).",
                "runner speed: RUN_SECONDS is 100 s, scaled to 130 s.",
                "runner speed: LOOP_SECONDS is 10 s, scaled to 13 s.",
            ],
        )


class Processor(unittest.TestCase):
    def test_it_names_the_processor_by_its_model_and_says_unknown_where_none_is_named(self):
        cpuinfo = "processor\t: 0\nvendor_id\t: AuthenticAMD\nmodel name\t: AMD EPYC 7763 64-Core Processor\n"

        self.assertEqual(runner_speed.processor(cpuinfo), "AMD EPYC 7763 64-Core Processor")
        self.assertEqual(runner_speed.processor(""), "unknown")


if __name__ == "__main__":
    unittest.main()
