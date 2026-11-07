"""What a push to the default branch drops, tested without a runner (ADR-0112)."""
import os
import unittest
from unittest import mock

import proven_push


class StillRunning(unittest.TestCase):
    def test_drops_a_job_whose_every_check_passed_on_the_identical_tree(self):
        concluded = {"checks": "success", "rules": "success", "the rules refuse violations": "success"}

        self.assertEqual(["tests"], proven_push.still_running(["checks", "guards", "tests"], concluded))

    def test_runs_a_job_one_of_whose_checks_did_not_pass_was_skipped_or_is_unknown(self):
        concluded = {"rules": "success", "the rules refuse violations": "failure", "checks": "skipped"}

        self.assertEqual(["guards", "checks", "phar"], proven_push.still_running(["guards", "checks", "phar"], concluded))

    def test_never_drops_a_job_it_holds_no_checks_for(self):
        self.assertEqual(
            ["mutation", "runner-contracts", "end-to-end"],
            proven_push.still_running(["mutation", "runner-contracts", "end-to-end"], {"mutation": "success"}),
        )


class IdenticalHead(unittest.TestCase):
    TREES = {"pushed": "t1", "same": "t1", "other": "t2"}

    def head(self, *pulls):
        with mock.patch.dict(os.environ, {"GITHUB_SHA": "pushed"}):
            return proven_push.identical_head(list(pulls), self.TREES.get)

    def test_finds_the_merged_pull_request_whose_tree_is_the_pushed_one(self):
        self.assertEqual("same", self.head(
            {"merged_at": "t", "head": {"sha": "other"}},
            {"merged_at": "t", "head": {"sha": "same"}},
        ))

    def test_finds_none_where_it_is_unmerged_or_another_tree(self):
        self.assertEqual("", self.head({"merged_at": None, "head": {"sha": "same"}}, {"merged_at": "t", "head": {"sha": "other"}}))


if __name__ == "__main__":
    unittest.main()
