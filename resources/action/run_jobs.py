"""What a run's jobs say, as GitHub lists them: what they took, whether a job `watch-jobs` names failed, and how
each shard job ended. gate_action.py reads the jobs and writes what these decide.
"""
from datetime import datetime, timezone
import re

# What a job's conclusion and status are once it failed and once it ended.
FAILURE = "failure"
COMPLETED = "completed"

# What an artifact's name may not hold, which a failed job's name is written without.
ARTIFACT_UNSAFE = re.compile(r'[":<>|*?\\/\r\n]')

# How often `watch` reads the run's jobs, and how long it waits for a watched job the run does not list.
WATCH_EVERY_SECONDS = 15
WATCH_UNLISTED_SECONDS = 300

# A shard job's name as GitHub lists it, which holds the shard's number: the
# reusable workflow names it `shard <number> (<label>)`, after the calling
# job's name.
SHARD_JOB = re.compile(r"(?:^| / )shard (\d+) \(")

# The file beside a shard's result that holds the attempt whose job wrote it.
ATTEMPT = ".attempt"

# The file in a shard's result's place, where its job left none, that says how
# the job ended: the gate names it in the verdict (see `ended`).
ENDED = ".ended.json"

# How a shard job that left no result ended, as that file names it: its runner
# received a shutdown signal, lost contact with GitHub, the matrix or the run
# cancelled it, it ran past its time limit, or it failed.
SHUTDOWN = "shutdown"
LOST = "lost"
CANCELLED = "cancelled"
TIMED_OUT = "timed-out"
FAILED = "failed"

# The fields of that file: how the job ended, the job's address, and the shard
# whose failure the matrix cancelled it after.
HOW = "how"
JOB = "job"
AFTER = "after"

# What a job's log says, in a line GitHub marks as an error, where its runner
# received a shutdown signal and where the job ran past its time limit.
SHUTDOWN_SAID = "The runner has received a shutdown signal"
TIMED_OUT_SAID = "has exceeded the maximum execution time"


def run_times(jobs: list[dict], now: datetime) -> dict | None:
    """The wall and runner seconds of a run's jobs, a job still running counted up to now, and when the plan's job
    began and what it took; none of a run with no job that started. Runner seconds are each job's own, not what
    GitHub bills, which rounds each up to a minute."""
    spans = []
    plan = {}
    for job in jobs:
        started = _instant(job.get("started_at"))
        if started is None:
            continue
        end = _instant(job.get("completed_at")) or now
        spans.append((started, end))
        if job.get("name") == "plan" or str(job.get("name", "")).endswith(" / plan"):
            at = started.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            plan = {"plan": {"at": at, "seconds": (end - started).total_seconds()}}
    if not spans:
        return None
    wall = max(end for _, end in spans) - min(start for start, _ in spans)
    runner = sum(((end - start).total_seconds() for start, end in spans), 0.0)
    return {"wallSeconds": wall.total_seconds(), "runnerSeconds": runner, **plan}


def _instant(text: object) -> datetime | None:
    """The instant an API timestamp names; none where it names none."""
    if not isinstance(text, str) or text == "":
        return None
    try:
        return datetime.fromisoformat(text)
    except ValueError:
        return None



def watched_names(text: str) -> list[str]:
    """The jobs `watch-jobs` names, one per line, blank lines left out."""
    return [line.strip() for line in text.splitlines() if line.strip()]


def watched_failure(jobs: list[dict], names: list[str]) -> str:
    """The first watched job that concluded in failure, in what an artifact's name may hold; none where none did."""
    failed = next(
        (job.get("name", "") for job in jobs if job.get("name") in names and job.get("conclusion") == FAILURE), ""
    )
    return ARTIFACT_UNSAFE.sub("-", failed)


def watch_settled(jobs: list[dict], names: list[str], unlisted_waited: bool) -> bool:
    """Whether no watched job can still fail: each one listed completed, and each is listed or the wait for it
    is over."""
    listed = [job for job in jobs if job.get("name") in names]
    every = {job.get("name") for job in listed} >= set(names)
    return all(job.get("status") == COMPLETED for job in listed) and (every or unlisted_waited)



def shard_attempts(jobs: list) -> dict[str, int]:
    """The attempt of each shard job's latest run, by the shard's number, from the run's jobs as GitHub lists them.

    A re-run lists the jobs it kept under its own attempt, each created anew and started when its earlier
    attempt started, so a job that started before it was created ran in an earlier attempt and counts for none.
    """
    latest: dict[str, int] = {}
    for job in jobs:
        named = SHARD_JOB.search(str(job.get("name", ""))) if isinstance(job, dict) and not kept(job) else None
        attempt = job.get("run_attempt") if named else None
        if named and isinstance(attempt, int):
            latest[named.group(1)] = max(attempt, latest.get(named.group(1), 0))
    return latest


def kept(job: dict) -> bool:
    """Whether a re-run listed this job as kept from an earlier attempt: it started before it was created."""
    started, created = job.get("started_at"), job.get("created_at")
    return isinstance(started, str) and isinstance(created, str) and started < created


def stale(written: dict[str, int], latest: dict[str, int]) -> list[str]:
    """The shards whose result an earlier attempt wrote than their job's latest run: that run wrote none."""
    return sorted((shard for shard, attempt in written.items() if attempt < latest.get(shard, 0)), key=int)


def endings(jobs: list, errors: dict[int, str]) -> dict[str, dict]:
    """How each shard job's latest run ended, by the shard's number, where it did not pass or skip.

    `errors` holds the lines a job's log marks as errors, by the job's id. A job GitHub cancelled is named
    beside the first shard job of its attempt that failed before it ended, which the matrix's fail-fast
    cancels the others for; a failed job whose steps never all ended lost its runner.
    """
    latest = shard_attempts(jobs)
    runs = {
        number: job
        for job in jobs
        if isinstance(job, dict) and not kept(job)
        for number in [_shard_number(job)]
        if number is not None and job.get("run_attempt") == latest.get(number)
    }
    failed = sorted(
        (str(job.get("completed_at", "")), number)
        for number, job in runs.items()
        if job.get("conclusion") == FAILURE
    )
    ended = {}
    for number, job in runs.items():
        how = ending(job, errors.get(job.get("id"), "") if isinstance(job.get("id"), int) else "")
        if how is None:
            continue
        ended[number] = {HOW: how} | ({JOB: job["html_url"]} if isinstance(job.get("html_url"), str) else {})
        if how == CANCELLED:
            before = [shard for at, shard in failed if shard != number and at <= str(job.get("completed_at", ""))]
            ended[number] |= {AFTER: int(before[0])} if before else {}
    return ended


def ending(job: dict, errors: str) -> str | None:
    """How a shard job ended, by its conclusion, its steps and its log's error lines; none where it passed or skipped."""
    conclusion = job.get("conclusion")
    steps = job.get("steps") if isinstance(job.get("steps"), list) else []
    if conclusion in (None, "success", "skipped", "neutral"):
        return None
    if SHUTDOWN_SAID in errors:
        return SHUTDOWN
    if TIMED_OUT_SAID in errors or conclusion == "timed_out":
        return TIMED_OUT
    if conclusion == "cancelled":
        return CANCELLED
    if any(isinstance(step, dict) and step.get("conclusion") is None for step in steps):
        return LOST
    return FAILED


def _shard_number(job: dict) -> str | None:
    named = SHARD_JOB.search(str(job.get("name", "")))
    return named.group(1) if named else None


def stale_text(shard: str, written: int, latest: int) -> str:
    """Why a shard's result is not judged."""
    return (
        f"Shard {shard} ran again in attempt {latest} and wrote no result, so the result attempt {written} "
        "wrote is not judged, and the verdict finds none for it."
    )
