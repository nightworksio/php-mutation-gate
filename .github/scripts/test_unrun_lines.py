"""The lines llvm-cov's report counts unrun, read from its text report."""
import unittest

import unrun_lines

REPORT = """/w/crates/a/src/lib.rs:
    1|      2|fn walked<F>(each: F) {
    2|      1|    one();
    3|      1|    other();
    4|      0|    never();
  ------------------
  | a::walked::<a::tests::One>:
  |    1|      1|fn walked<F>(each: F) {
  |    2|      1|    one();
  |    3|      0|    other();
  ------------------
  | a::walked::<a::tests::Other>:
  |    1|      1|fn walked<F>(each: F) {
  |    2|      0|    one();
  |    3|      1|    other();
  ------------------
/w/crates/a/src/once.rs:
    7|      1|fn once() {
  ------------------
  | a::once:
  |    7|      1|fn once() {
  ------------------
"""


class Unrun(unittest.TestCase):
    def test_names_what_the_best_copy_left_unrun_and_what_nothing_ran(self):
        self.assertEqual(
            ["/w/crates/a/src/lib.rs:3 a::walked", "/w/crates/a/src/lib.rs:4"],
            unrun_lines.unrun(REPORT),
        )

    def test_reads_a_function_s_name_without_its_type_arguments(self):
        self.assertEqual("a::told::{closure#0}", unrun_lines.generic_free("a::told::<W, Vec<u8>>::{closure#0}"))
        self.assertEqual("a::once", unrun_lines.generic_free("a::once"))

    def test_names_nothing_where_every_line_ran(self):
        self.assertEqual([], unrun_lines.unrun("/w/a.rs:\n    1|      3|fn a() {\n"))


if __name__ == "__main__":
    unittest.main()
