#!/usr/bin/env python3
"""Ends a timed run that makes no progress, whatever its budget.

Usage: progress_watch.py <pid> <seconds> <report>

It reads the process tree under <pid> from /proc once a second. The run
made progress in a second where any of these holds:

- a process of the tree started or ended;
- a process with no child of its own, which does the work (a test, a
  mutant's run, the gate before and after its workers), used any CPU;
- a process with children, which hands them work and waits on them, used
  more than POLL_SHARE of a CPU: more than its waiting costs, so it was
  working itself while its children waited on it.

Where none holds for <seconds>, the run is stuck: it writes why to
<report>, kills the whole tree, and ends. So a worker blocked on its
parent's pipe while the parent works, the gate waiting on its busy
children, and one test asleep while another works never stop a run;
every process of the tree waiting at once does. The bound is not scaled
to the runner: a slower runner takes longer to do the work, never longer
to do nothing (ADR-0159). It ends by itself when <pid> ends, and says the
longest stretch it saw without progress.
"""
import os
import signal
import sys
import time

POLL_SHARE = 0.1


def stat_of(text: str) -> tuple[int, str, int]:
    """The parent pid, the command name and the CPU ticks used, of one /proc/<pid>/stat."""
    name = text[text.index("(") + 1 : text.rindex(")")]
    fields = text[text.rindex(")") + 2 :].split()
    return int(fields[1]), name, int(fields[11]) + int(fields[12])


def tree(parents: dict[int, int], root: int) -> set[int]:
    """The processes under root, root among them."""
    under = {root}
    grown = True
    while grown:
        more = {pid for pid, parent in parents.items() if parent in under} - under
        under |= more
        grown = bool(more)
    return under


def leaves(parents: dict[int, int], under: set[int]) -> set[int]:
    """Of these processes, those that are no other's parent."""
    return under - {parents[pid] for pid in under if pid in parents}


def progressed(before: dict[int, int], after: dict[int, int], working: set[int], seconds: float, hz: int) -> bool:
    """Whether the tree made progress between two readings of each process's ticks, so many seconds apart."""
    if before.keys() != after.keys():
        return True
    share = POLL_SHARE * seconds * hz
    return any(
        after[pid] - before[pid] > (0 if pid in working else share)
        for pid in after
    )


def reading(root: int) -> tuple[dict[int, int], set[int], dict[int, str]]:
    """The ticks of each process under root, those with no child, and their names."""
    seen = {}
    for entry in os.listdir("/proc"):
        if entry.isdigit():
            try:
                with open(f"/proc/{entry}/stat", encoding="utf-8") as stat:
                    seen[int(entry)] = stat_of(stat.read())
            except (OSError, ValueError):
                continue
    parents = {pid: parent for pid, (parent, _, _) in seen.items()}
    under = tree(parents, root)
    return (
        {pid: seen[pid][2] for pid in under if pid in seen},
        leaves(parents, under),
        {pid: seen[pid][1] for pid in under if pid in seen},
    )


def running(pid: int) -> bool:
    """Whether a process is there and not yet ended: a process that ended and is not yet reaped is not running."""
    try:
        with open(f"/proc/{pid}/stat", encoding="utf-8") as stat:
            text = stat.read()
    except OSError:
        return False
    return text[text.rindex(")") + 2 :].split()[0] != "Z"


def main(root: int, seconds: float, report: str) -> int:
    hz = os.sysconf("SC_CLK_TCK")
    before, _, _ = reading(root)
    then = time.monotonic()
    quiet = then
    longest = 0.0
    while running(root):
        time.sleep(1)
        now = time.monotonic()
        after, working, names = reading(root)
        if progressed(before, after, working, now - then, hz):
            quiet = now
        longest = max(longest, now - quiet)
        if now - quiet >= seconds:
            with open(report, "w", encoding="utf-8") as said:
                waiting = ", ".join(f"{names[pid]} (pid {pid})" for pid in sorted(after))
                said.write(f"no progress for {seconds:.0f} s: no process used CPU, of {waiting}.\n")
            for pid in sorted(after, reverse=True):
                try:
                    os.kill(pid, signal.SIGKILL)
                except OSError:
                    continue
            break
        before, then = after, now
    print(f"progress watch: the longest stretch without progress was {longest:.0f} s, of {seconds:.0f} s allowed.")
    return 0


if __name__ == "__main__":
    sys.exit(main(int(sys.argv[1]), float(sys.argv[2]), sys.argv[3]))
