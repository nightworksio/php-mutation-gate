#!/usr/bin/env python3
"""Ends a timed run whose process makes no progress, whatever its budget.

Usage: progress_watch.py <pid> <seconds> <report>

It watches the process tree under <pid> once a second, by /proc alone. A
process of that tree with no child of its own, a leaf, that uses no CPU for
<seconds> is stuck: it writes why to <report>, kills the whole tree, and
ends. The bound is not scaled to the runner: a slower runner takes longer to
do the work, never longer to do nothing, so a scaled budget cannot let a
stuck process sit (ADR-0159). It ends by itself when <pid> ends.
"""
import os
import signal
import sys
import time


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


def stalled(since: dict[int, float], now: float, seconds: float) -> int | None:
    """A process whose CPU ticks last moved at least so many seconds ago; none where every one moved since."""
    return next((pid for pid, moved in sorted(since.items()) if now - moved >= seconds), None)


def snapshot() -> dict[int, tuple[int, str, int]]:
    seen = {}
    for entry in os.listdir("/proc"):
        if entry.isdigit():
            try:
                with open(f"/proc/{entry}/stat", encoding="utf-8") as stat:
                    seen[int(entry)] = stat_of(stat.read())
            except (OSError, ValueError):
                continue
    return seen


def main(root: int, seconds: float, report: str) -> int:
    ticks: dict[int, int] = {}
    since: dict[int, float] = {}
    while os.path.exists(f"/proc/{root}"):
        now = time.monotonic()
        seen = snapshot()
        parents = {pid: parent for pid, (parent, _, _) in seen.items()}
        under = tree(parents, root)
        watched = leaves(parents, under)
        for pid in watched:
            used = seen[pid][2]
            if ticks.get(pid) != used:
                ticks[pid] = used
                since[pid] = now
        since = {pid: moved for pid, moved in since.items() if pid in watched}
        stuck = stalled(since, now, seconds)
        if stuck is not None:
            with open(report, "w", encoding="utf-8") as said:
                said.write(f"no progress for {seconds:.0f} s: {seen[stuck][1]} (pid {stuck}) used no CPU.\n")
            for pid in sorted(under, reverse=True):
                try:
                    os.kill(pid, signal.SIGKILL)
                except OSError:
                    continue
            return 0
        time.sleep(1)
    return 0


if __name__ == "__main__":
    sys.exit(main(int(sys.argv[1]), float(sys.argv[2]), sys.argv[3]))
