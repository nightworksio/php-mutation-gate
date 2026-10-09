"""The deciding half of hook_managers.py: python3 -m unittest discover .github/scripts"""
import unittest

import hook_managers


def every_step_as_expected() -> dict[str, tuple[int, str]]:
    return {
        step: (0 if succeeds else 1, " ".join(holds))
        for step, (succeeds, holds) in hook_managers.EXPECTED.items()
    }


class Judge(unittest.TestCase):
    def test_finds_nothing_where_every_step_did_as_expected(self):
        self.assertEqual(hook_managers.judge(every_step_as_expected()), [])

    def test_names_a_push_that_passed_where_it_must_be_refused(self):
        ran = every_step_as_expected()
        ran["pre-commit push survived"] = (0, "Nothing is pushed, so there is nothing to judge.")
        found = hook_managers.judge(ran)
        self.assertEqual([item["rule"] for item in found], ["unexpected"])
        self.assertTrue(found[0]["detail"].startswith(
            "pre-commit push survived: exited 0, expected non-zero; missing ['mutation-gate: failed']\n"
        ))
        self.assertIn("Nothing is pushed", found[0]["detail"])

    def test_names_a_step_that_failed_where_it_must_pass(self):
        ran = every_step_as_expected()
        ran["grumphp commit"] = (1, "shell: sh: vendor/bin/mutation-gate: not found")
        found = hook_managers.judge(ran)
        self.assertEqual([item["rule"] for item in found], ["unexpected"])
        self.assertTrue(found[0]["detail"].startswith("grumphp commit: exited 1, expected 0\n"))

    def test_names_a_step_whose_output_lacks_what_it_must_hold(self):
        ran = every_step_as_expected()
        ran["captainhook push survived"] = (1, "captainhook: config not found")
        found = hook_managers.judge(ran)
        self.assertEqual(
            found[0]["detail"].splitlines()[0],
            "captainhook push survived: exited 1, expected non-zero; missing ['mutation-gate: failed']",
        )

    def test_names_each_step_that_never_ran(self):
        ran = every_step_as_expected()
        del ran["captainhook commit"]
        self.assertEqual(
            hook_managers.judge(ran),
            [{"path": "", "line": 0, "rule": "not-run", "detail": "captainhook commit: never ran"}],
        )

    def test_keeps_only_the_end_of_a_long_output(self):
        ran = every_step_as_expected()
        ran["grumphp commit"] = (1, "x" * 5000 + "the end")
        detail = hook_managers.judge(ran)[0]["detail"]
        self.assertTrue(detail.endswith("the end"))
        self.assertEqual(len(detail.splitlines()[1]), hook_managers.TAIL)


class LocalRepository(unittest.TestCase):
    def test_points_the_config_at_the_checkout_and_its_commit(self):
        config = (
            "default_install_hook_types: [pre-commit, pre-push]\n"
            "repos:\n"
            "    - repo: https://github.com/nightworksio/php-mutation-gate\n"
            "      rev: <the commit of a release>\n"
            "      hooks:\n"
            "        - id: mutation-gate-pre-push\n"
        )
        self.assertEqual(
            hook_managers.local_repository(config, "/work/gate", "abc123"),
            "default_install_hook_types: [pre-commit, pre-push]\n"
            "repos:\n"
            "    - repo: /work/gate\n"
            "      rev: abc123\n"
            "      hooks:\n"
            "        - id: mutation-gate-pre-push\n",
        )


class Environment(unittest.TestCase):
    def test_leaves_out_what_tells_the_gate_it_runs_in_ci(self):
        saved = dict(hook_managers.os.environ)
        try:
            hook_managers.os.environ.update({"CI": "true", "GITHUB_ACTIONS": "true", "RUNNER_TEMP": "/tmp", "HOME": "/home"})
            kept = hook_managers.environment()
            self.assertNotIn("CI", kept)
            self.assertNotIn("GITHUB_ACTIONS", kept)
            self.assertNotIn("RUNNER_TEMP", kept)
            self.assertEqual(kept["HOME"], "/home")
        finally:
            hook_managers.os.environ.clear()
            hook_managers.os.environ.update(saved)


class Usage(unittest.TestCase):
    def test_refuses_arguments_it_does_not_take(self):
        self.assertEqual(hook_managers.main(["hook_managers.py", "gate"]), 2)


if __name__ == "__main__":
    unittest.main()
