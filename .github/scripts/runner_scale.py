#!/usr/bin/env python3
"""The budgets of a timed step, scaled to how fast this runner is.

Usage: mutation-gate machine -- <suite>... | runner_scale.py <budget>...

It reads the one JSON line `mutation-gate machine` prints: the
processor, the rounds timed and their median seconds of the project's suite
under plain PHPUnit with pcov, the work the gate's coverage phase does with
none of the gate's code in it, so no change to the gate can slow it. Its
ratio to RUNNER_REFERENCE_SECONDS, what the suite takes on a typical runner,
is the scale: never below 1, so a fast runner keeps the base budgets, and
never above RUNNER_MOST_SCALE, past which the runner is too slow to judge on
and the step fails with what it measured. Each budget named, an environment
variable of seconds, is scaled and written to GITHUB_ENV as <budget>_SCALED,
and every number goes to the log and the step summary (ADR-0159).
"""
import json
import math
import os
import sys


def scale(measured: float, reference: float) -> float:
    """How much slower than the reference this runner is; 1 for one as fast or faster."""
    return max(1.0, measured / reference)


def scaled(base: int, factor: float) -> int:
    """A budget of whole seconds, scaled, rounded up past the float's last digits."""
    return math.ceil(round(base * factor, 6))


def lines(machine: dict, reference: float, factor: float, most: float, budgets: dict[str, int]) -> list[str]:
    """What the log and the step summary say of the runner and the budgets."""
    said = [
        f"runner speed: the processor is {machine.get('cpu', 'unknown')}.",
        f"runner speed: the suite under pcov took {machine['seconds']:.2f} s, the median of {machine['rounds']}; "
        f"the reference is {reference:.2f} s, so the scale is {factor:.2f} (at most {most:.2f}).",
    ]
    said += [f"runner speed: {name} is {base} s, scaled to {scaled(base, factor)} s." for name, base in budgets.items()]
    return said


def main(names: list[str]) -> int:
    reference = float(os.environ["RUNNER_REFERENCE_SECONDS"])
    most = float(os.environ["RUNNER_MOST_SCALE"])
    budgets = {name: int(os.environ[name]) for name in names}
    machine = json.loads(sys.stdin.read())
    factor = scale(machine["seconds"], reference)
    said = lines(machine, reference, factor, most, budgets)
    print("\n".join(said))
    with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
        summary.write("".join(f"{line}\n" for line in said))
    if factor > most:
        print(
            f"::error title=runner speed::The runner took {machine['seconds']:.2f} s for a suite the reference runner "
            f"runs in {reference:.2f} s: {factor:.2f} times as long, more than the {most:.2f} a verdict is judged on."
        )
        return 1
    with open(os.environ["GITHUB_ENV"], "a", encoding="utf-8") as env:
        env.write("".join(f"{name}_SCALED={scaled(base, factor)}\n" for name, base in budgets.items()))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
