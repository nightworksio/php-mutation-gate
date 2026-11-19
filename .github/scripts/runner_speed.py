#!/usr/bin/env python3
"""How fast this runner is, and the budgets of a timed step scaled by it.

Usage: runner_speed.py <budget>...

Run in the prepared project, it times the project's own suite under plain
PHPUnit with pcov, the work the gate's coverage phase does but with none of
the gate's code, so no change to the gate can slow it: once to warm up, then
REPEATS times, and takes the median. Its ratio to
RUNNER_REFERENCE_SECONDS, what the workload takes on a typical runner, is the
scale: never below 1, so a fast runner keeps the base budgets, and never
above RUNNER_MOST_SCALE, past which the runner is too slow to judge on and
the step fails with what it measured. Each budget named, an environment
variable of seconds, is scaled and written to GITHUB_ENV as <budget>_SCALED,
and every number goes to the log and the step summary (ADR-0159).

Two halves: `median`, `scale` and `scaled` decide the numbers, and `main`
times the workload and writes them.
"""
import math
import os
import statistics
import subprocess
import sys
import time
from pathlib import Path

REPEATS = 5
SUITE = ["php", "-d", "pcov.enabled=1", "vendor/bin/phpunit", "--coverage-php=/dev/null", "--do-not-cache-result"]


def median(times: list[float]) -> float:
    """The median of the workload's times, which one slow repeat does not move."""
    return statistics.median(times)


def scale(measured: float, reference: float) -> float:
    """How much slower than the reference this runner is; 1 for one as fast or faster."""
    return max(1.0, measured / reference)


def scaled(base: int, factor: float) -> int:
    """A budget of whole seconds, scaled, rounded up past the float's last digits."""
    return math.ceil(round(base * factor, 6))


def lines(measured: float, reference: float, factor: float, most: float, budgets: dict[str, int]) -> list[str]:
    """What the log and the step summary say of the runner and the budgets."""
    said = [
        f"runner speed: the suite under pcov took {measured:.2f} s, the median of {REPEATS}; "
        f"the reference is {reference:.2f} s, so the scale is {factor:.2f} (at most {most:.2f})."
    ]
    said += [f"runner speed: {name} is {base} s, scaled to {scaled(base, factor)} s." for name, base in budgets.items()]
    return said


def processor(cpuinfo: str) -> str:
    """The processor's model, as /proc/cpuinfo names it; unknown where it names none."""
    for line in cpuinfo.splitlines():
        if line.startswith("model name"):
            return line.split(":", 1)[1].strip()
    return "unknown"


def timed() -> float:
    """The seconds one run of the project's suite under pcov took."""
    started = time.monotonic()
    subprocess.run(SUITE, check=True, capture_output=True)
    return time.monotonic() - started


def main(names: list[str]) -> int:
    reference = float(os.environ["RUNNER_REFERENCE_SECONDS"])
    most = float(os.environ["RUNNER_MOST_SCALE"])
    budgets = {name: int(os.environ[name]) for name in names}
    timed()
    measured = median([timed() for _ in range(REPEATS)])
    factor = scale(measured, reference)
    cpuinfo = Path("/proc/cpuinfo")
    said = [
        f"runner speed: the processor is {processor(cpuinfo.read_text() if cpuinfo.exists() else '')}.",
        *lines(measured, reference, factor, most, budgets),
    ]
    print("\n".join(said))
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
        summary.write("".join(f"{line}\n" for line in said))
    if factor > most:
        print(
            f"::error title=runner speed::The runner took {measured:.2f} s for a suite the reference runner runs "
            f"in {reference:.2f} s: {factor:.2f} times as long, more than the {most:.2f} a verdict is judged on."
        )
        return 1
    with open(os.environ["GITHUB_ENV"], "a", encoding="utf-8") as env:
        env.write("".join(f"{name}_SCALED={scaled(base, factor)}\n" for name, base in budgets.items()))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
