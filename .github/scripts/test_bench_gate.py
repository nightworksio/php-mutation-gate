import os
import subprocess
import tempfile
import unittest
from pathlib import Path

GATE = Path(__file__).resolve().parents[2] / "bench" / "gate.sh"

FAKE_COMPOSER = """#!/usr/bin/env bash
printf '%s\\n' "$*" >> "${COMPOSER_LOG}"
"""


def resolved(work: Path, lock: str | None, *command: str) -> tuple[list[str], str | None]:
    """The Composer commands bench_resolve ran in a project directory, and the lock it left there."""
    project, bin_dir = work / "project", work / "bin"
    project.mkdir()
    bin_dir.mkdir()
    composer = bin_dir / "composer"
    composer.write_text(FAKE_COMPOSER)
    composer.chmod(0o755)
    log = work / "composer.log"
    env = {**os.environ, "PATH": f"{bin_dir}:{os.environ['PATH']}", "COMPOSER_LOG": str(log),
           "GITHUB_WORKSPACE": str(GATE.parents[1])}
    env.pop("BENCH_LOCK", None)
    if lock is not None:
        (work / "planned.lock").write_text(lock)
        env["BENCH_LOCK"] = str(work / "planned.lock")
    subprocess.run(["bash", "-c", f'source "{GATE}" && bench_resolve "$@"', "bench", *command], cwd=project,
                   env=env, check=True)
    left = project / "composer.lock"
    return log.read_text().splitlines(), left.read_text() if left.exists() else None


class Resolve(unittest.TestCase):
    def test_runs_the_command_as_given_where_no_lock_is_named(self):
        with tempfile.TemporaryDirectory() as work:
            self.assertEqual(resolved(Path(work), None, "update", "--no-interaction"),
                             (["update --no-interaction"], None))

    def test_installs_from_the_named_lock_in_place_of_an_update(self):
        with tempfile.TemporaryDirectory() as work:
            self.assertEqual(resolved(Path(work), '{"packages": []}', "update", "--with-all-dependencies"),
                             (["install --no-interaction --no-progress"], '{"packages": []}'))

    def test_changes_composer_json_as_a_require_would_then_installs_from_the_lock_without_its_scripts(self):
        with tempfile.TemporaryDirectory() as work:
            self.assertEqual(resolved(Path(work), "{}", "require", "--dev", "--no-scripts", "acme/gate:@dev"), ([
                "require --dev --no-scripts acme/gate:@dev --no-update",
                "install --no-interaction --no-progress --no-scripts",
            ], "{}"))


if __name__ == "__main__":
    unittest.main()
