"""runner_scale.py's arithmetic and lines: python3 -m unittest discover .github/scripts"""
import unittest

import runner_scale


class Scale(unittest.TestCase):
    def test_it_scales_by_how_much_slower_the_runner_is_and_never_below_one(self):
        self.assertEqual(runner_scale.scale(1.5, 1.0), 1.5)
        self.assertEqual(runner_scale.scale(1.0, 1.0), 1.0)
        self.assertEqual(runner_scale.scale(0.5, 1.0), 1.0)

    def test_it_rounds_a_scaled_budget_up_to_whole_seconds(self):
        self.assertEqual(runner_scale.scaled(100, 1.0), 100)
        self.assertEqual(runner_scale.scaled(100, 1.234), 124)
        self.assertEqual(runner_scale.scaled(10, 1.01), 11)

    def test_it_says_the_processor_the_measured_time_the_reference_the_scale_and_every_budget_both_ways(self):
        machine = {"cpu": "AMD EPYC 7763 64-Core Processor", "cores": 4, "rounds": 5, "seconds": 1.3}
        said = runner_scale.lines(machine, 1.0, 1.3, 2.5, {"RUN_SECONDS": 100, "LOOP_SECONDS": 10})

        self.assertEqual(
            said,
            [
                "runner speed: the processor is AMD EPYC 7763 64-Core Processor.",
                "runner speed: the suite under pcov took 1.30 s, the median of 5; "
                "the reference is 1.00 s, so the scale is 1.30 (at most 2.50).",
                "runner speed: RUN_SECONDS is 100 s, scaled to 130 s.",
                "runner speed: LOOP_SECONDS is 10 s, scaled to 13 s.",
            ],
        )

    def test_it_says_unknown_where_the_machine_names_no_processor(self):
        machine = {"cores": 4, "rounds": 5, "seconds": 0.4}

        self.assertEqual(runner_scale.lines(machine, 1.0, 1.0, 2.5, {})[0], "runner speed: the processor is unknown.")


if __name__ == "__main__":
    unittest.main()
