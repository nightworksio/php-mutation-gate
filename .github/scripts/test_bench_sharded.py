"""The benchmark's sharded arm: python3 -m unittest discover .github/scripts"""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import bench_sharded


def git(directory: Path, *arguments: str) -> str:
    return subprocess.run(["git", "-C", str(directory), *arguments], check=True, capture_output=True,
                          text=True).stdout.strip()


def times(out: Path, seconds: dict[str, float]) -> None:
    for name, each in seconds.items():
        (out / f"{name}.time").write_text(json.dumps({"seconds": each, "exit": 0}))


# A gate that plans two shards on the commit HEAD is at, runs a shard into
# the results directory it is given, and judges every result it finds there,
# each mutant a shard's result holds killed; it logs what each run found.
FAKE_GATE = """#!/usr/bin/env python3
import json, pathlib, subprocess, sys
command, options = sys.argv[1], dict(part[2:].split('=', 1) for part in sys.argv[2:] if '=' in part)
state = pathlib.Path('.mutation-gate')
with open(pathlib.Path(__file__).with_suffix('.log'), 'a') as log:
    log.write(json.dumps({'command': command, 'config': options.get('config'),
                          'results': sorted(p.name for p in (state / 'results').glob('*'))}) + '\\n')
if command == 'plan':
    head = subprocess.run(['git', 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
    (state / 'coverage').mkdir(parents=True)
    (state / 'coverage' / 'map.json.gz').write_text('map')
    (state / 'coverage' / 'coverage.php').write_text('<?php')
    (state / 'plan.json').write_text(json.dumps({'commit': head, 'shards': [{'id': 1}, {'id': 2}]}))
elif command == 'run':
    results = pathlib.Path(options['results'])
    results.mkdir(parents=True, exist_ok=True)
    (results / f"{options['shard']}.json").write_text(json.dumps({'mutants': [options['shard']]}))
elif command == 'verdict':
    found = sorted(pathlib.Path(options['results']).glob('*.json'))
    mutants = [{'file': f'src/Shard{p.stem}.php', 'line': 1, 'mutator': 'Plus', 'diff': '', 'status': 'killed'}
               for p in found]
    pathlib.Path(options['report'].split(':', 1)[1]).write_text(json.dumps({'mutants': mutants}))
"""


def project(gate: str) -> dict:
    return {
        "project": "acme/money", "version": "1.0.0", "commit": "abc",
        "arms": {"gate-infection": {"said": "the gate", "report": "gate", "copy": {"baseline.json": "bench.json"},
                                    "command": [gate, "run", "--no-interaction", "--config=bench-infection.json",
                                                "--report=json:{report}"]}},
        "sharded": {"arm": "gate-infection", "said": "the gate sharded"},
    }


class Command(unittest.TestCase):
    def test_runs_the_gate_arm_s_program_with_its_config(self):
        self.assertEqual(bench_sharded.gate(project("vendor/bin/mutation-gate"), "plan", "--full"),
                         ["vendor/bin/mutation-gate", "plan", "--full", "--no-interaction",
                          "--config=bench-infection.json"])


class Rounds(unittest.TestCase):
    def test_takes_the_slowest_shard_for_the_wall_time_and_every_shard_for_the_runner_time(self):
        with tempfile.TemporaryDirectory() as work:
            out = Path(work)
            times(out, {"plan": 10, "setup-plan": 1, "setup-verdict": 2, "setup-shard-1": 3, "setup-shard-2": 4,
                        "shard-1-1": 100, "shard-2-1": 50, "verdict-1": 5})
            self.assertEqual(bench_sharded.rounds_of(out, [1, 2], 1), [{
                "wall": 115, "runner": 165, "wallWithSetup": 1 + 10 + 103 + 2 + 5, "runnerWithSetup": 1 + 10 + 150 + 7 + 2 + 5,
                "exits": [0, 0, 0],
            }])

    def test_counts_a_time_it_did_not_keep_as_none_and_absent(self):
        with tempfile.TemporaryDirectory() as work:
            self.assertEqual(bench_sharded.rounds_of(Path(work), [1], 1)[0]["exits"], ["absent", "absent"])


class Running(unittest.TestCase):
    def test_plans_once_runs_each_shard_from_the_plan_each_round_and_judges_each_round(self):
        with tempfile.TemporaryDirectory() as work:
            directory, out = Path(work) / "project", Path(work) / "out"
            directory.mkdir()
            gate = Path(work) / "gate.py"
            gate.write_text(FAKE_GATE)
            gate.chmod(0o755)
            (directory / "bench.json").write_text("{}")
            (directory / ".gitignore").write_text(".mutation-gate/\nbaseline.json\n")
            (directory / "composer.lock").write_text('{"packages": [{"name": "lcobucci/clock", "version": "3.6.0"}]}')
            git(directory, "init", "--quiet")
            git(directory, "add", "--all")
            git(directory, "-c", "user.name=t", "-c", "user.email=t@localhost", "commit", "--quiet", "-m", "prepared")
            described = project(str(gate))
            output = Path(work) / "output"
            os.environ["GITHUB_OUTPUT"] = str(output)
            try:
                self.assertEqual(bench_sharded.planned(described, directory, out), 0)
            finally:
                del os.environ["GITHUB_OUTPUT"]
            self.assertEqual(output.read_text(), "shards=[1, 2]\n")
            self.assertEqual(sorted(path.name for path in (out / "planned" / "coverage").iterdir()), ["map.json.gz"])
            self.assertEqual((out / "composer.lock").read_text(), (directory / "composer.lock").read_text())
            for shard in ("1", "2"):
                bench_sharded.shard(described, directory, out, out / "planned", shard, 2, None)
            bench_sharded.verdict(described, directory, out, out / "planned", 2)
            runs = [json.loads(line) for line in gate.with_suffix(".log").read_text().splitlines()]
            self.assertEqual([each["command"] for each in runs], ["plan", "run", "run", "run", "run", "verdict", "verdict"])
            self.assertEqual({each["config"] for each in runs}, {"bench-infection.json"})
            self.assertEqual([each["results"] for each in runs], [[]] * 5 + [["1.json", "2.json"]] * 2)
            self.assertEqual(sorted(path.name for path in (out / "results-2").iterdir()), ["1.json", "2.json"])
            self.assertTrue((directory / "baseline.json").exists())
            result = Path(work) / "result.json"
            self.assertEqual(bench_sharded.report(described, out, result, 2), 0)
            arm = json.loads(result.read_text())["arms"]["gate-sharded"]
            self.assertEqual((arm["shards"], arm["machines"], len(arm["wall"]["rounds"])), (2, 4, 2))
            self.assertEqual(arm["files"]["total"]["killed"], 2)

    def test_refuses_a_plan_made_on_another_commit(self):
        with tempfile.TemporaryDirectory() as work:
            directory, planned = Path(work) / "project", Path(work) / "planned"
            directory.mkdir()
            planned.mkdir()
            (directory / "bench.json").write_text("{}")
            git(directory, "init", "--quiet")
            git(directory, "add", "--all")
            git(directory, "-c", "user.name=t", "-c", "user.email=t@localhost", "commit", "--quiet", "-m", "prepared")
            (planned / "plan.json").write_text(json.dumps({"commit": "0" * 40, "shards": []}))
            with self.assertRaisesRegex(RuntimeError, "The plan was made on 0{40}"):
                bench_sharded.restored(project(sys.executable), directory, planned)


if __name__ == "__main__":
    unittest.main()
