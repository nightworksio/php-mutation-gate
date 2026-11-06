#!/usr/bin/env python3
"""The relay of numbers out of `workflow_run`, and the re-run of infrastructure failures (ADR-0019).

Usage: bot_relay.py rerun|relay

`workflow_run` writes the cache main's ledger is restored from, so these
steps handle numbers alone: the run's id from RUN_ID, and what GitHub
answers about it. They read no string a pull request controls into anything
they write, download no artifact, check nothing out and install nothing.

- `rerun`, with the job's token and `actions: write`: where every failed job
  of a run failed at a step main's config names as the infrastructure's, or
  lost its runner, the failed jobs are re-run, once per job and head: only the
  head's first run is, at its first attempt. It writes
  `reran=<job>:<why>,...` to $GITHUB_OUTPUT.
- `relay`, with the App's token in APP_TOKEN: it finds the pull request whose
  head is the run's, and sets its sticky comment to the explaining state, with
  a mark naming the run and its head. It relays a run that completed, or one
  whose failed jobs `rerun` just re-ran (RERAN). The App's edit starts the
  explainer, on `issue_comment`, whose cache is read-only.

Two halves: the functions above `rerun` decide from GitHub's answers alone,
and the ones below ask it.
"""
import os
import re
import sys

import bot_sticky
import bot_tables
import github_api

NUMBER = re.compile(r"\A[1-9][0-9]{0,19}\Z")
SHA = re.compile(r"\A[0-9a-f]{40}\Z")
LOGIN = re.compile(r"\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\Z")
BRANCH = re.compile(r"\A[A-Za-z0-9._/-]{1,255}\Z")
KIND = re.compile(r"\A[a-z-]{1,32}\Z")

APP_TOKEN = "APP_TOKEN"


def kind_of(job: dict, steps: dict[str, list[str]]) -> str:
    """Why a failed job is the infrastructure's: the kind of step it failed at, or a lost runner; '' where it is not."""
    failed = [step for step in job.get("steps") or [] if step.get("conclusion") == "failure"]
    if not failed:
        return "runner" if "runner" in steps else ""
    name = failed[0].get("name") if isinstance(failed[0].get("name"), str) else ""
    return next((kind for kind, prefixes in steps.items() if name.startswith(tuple(prefixes))), "")


def to_rerun(run: dict, earliest: int, jobs: list[dict], config: dict) -> list[tuple[int, str]]:
    """The failed jobs of a run to re-run, with why: only on the head's first run, at its first attempt, and only where every failed job is the infrastructure's."""
    if run.get("conclusion") not in config["conclusions"] or run.get("run_attempt") != 1 or int(run["id"]) != earliest:
        return []
    failed = [job for job in jobs if job.get("conclusion") in config["conclusions"]]
    kinds = [(int(job["id"]), kind_of(job, config["steps"])) for job in failed]
    if not kinds or any(not kind for _, kind in kinds):
        return []
    return kinds


def parse_reran(text: str) -> list[tuple[int, str]]:
    """What `rerun` wrote, validated again: job ids and kinds."""
    found = []
    for pair in filter(None, text.split(",")):
        job, _, kind = pair.partition(":")
        if NUMBER.match(job) and KIND.match(kind):
            found.append((int(job), kind))
    return found


def _run(tables: bot_tables.Tables) -> dict | None:
    """The run RUN_ID names, where it is a relayed workflow's run of a pull request."""
    run_id = os.environ.get("RUN_ID", "")
    if not NUMBER.match(run_id):
        raise ValueError("RUN_ID is not a run's id")
    run = github_api.call("GET", f"actions/runs/{run_id}")
    if run.get("event") != "pull_request" or run.get("path") not in tables.config["relay"]["workflows"].values():
        return None
    if not isinstance(run.get("head_sha"), str) or not SHA.match(run["head_sha"]):
        raise ValueError("GitHub's answer is not of the shape the bot reads")
    return run


def _output(values: dict[str, str]) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        for name, value in values.items():
            output.write(f"{name}={value}\n")


def reran_of(run: dict, config: dict) -> list[tuple[int, str]]:
    """The failed jobs of a run's first attempt that are, or were, re-run for the infrastructure, with why."""
    run_id = int(run["id"])
    workflow = int(run["workflow_id"])
    earlier = github_api.listed(f"actions/workflows/{workflow}/runs?head_sha={run['head_sha']}&event=pull_request", "workflow_runs")
    earliest = min((int(item["id"]) for item in earlier), default=run_id)
    first = run if run.get("run_attempt") == 1 else github_api.call("GET", f"actions/runs/{run_id}/attempts/1")
    jobs = github_api.listed(f"actions/runs/{run_id}/attempts/1/jobs", "jobs")
    return to_rerun({**first, "id": run_id}, earliest, jobs, config)


def rerun(tables: bot_tables.Tables) -> None:
    run = _run(tables)
    reran = []
    if run is not None and run.get("run_attempt") == 1:
        reran = reran_of(run, tables.config["rerun"])
        if reran:
            github_api.call("POST", f"actions/runs/{int(run['id'])}/rerun-failed-jobs")
    _output({"reran": ",".join(f"{job}:{kind}" for job, kind in reran)})


def pull_of(run: dict) -> int:
    """The open pull request whose head is the run's: by the numbers the run lists, or for a fork by its head repository."""
    sha = run["head_sha"]
    for listed in run.get("pull_requests") or []:
        number = int(listed["number"])
        found = github_api.call("GET", f"pulls/{number}")
        if found.get("state") == "open" and found["head"]["sha"] == sha:
            return number
    owner = ((run.get("head_repository") or {}).get("owner") or {}).get("login", "")
    branch = run.get("head_branch") or ""
    if not (isinstance(owner, str) and LOGIN.match(owner) and isinstance(branch, str) and BRANCH.match(branch)):
        return 0
    head = github_api.quoted(f"{owner}:{branch}")
    for found in github_api.call("GET", f"pulls?state=open&head={head}&per_page=10"):
        if found["head"]["sha"] == sha:
            return int(found["number"])
    return 0


def relay(tables: bot_tables.Tables) -> None:
    run = _run(tables)
    reran = parse_reran(os.environ.get("RERAN", ""))
    if run is None or (run.get("status") != "completed" and not reran):
        return
    number = pull_of(run)
    if not number:
        return
    key = next(key for key, path in tables.config["relay"]["workflows"].items() if run["path"] == path)
    mark = bot_sticky.relay_mark(int(run["id"]), run["head_sha"])
    line = tables.say("explaining", workflow=key, run=github_api.web_url(f"actions/runs/{int(run['id'])}"), sha=run["head_sha"][:7])
    sticky = bot_sticky.find(number)
    body = bot_sticky.relaying(sticky["body"] if sticky else "", mark, line)
    if sticky is None:
        github_api.call("POST", f"issues/{number}/comments", {"body": body}, token=APP_TOKEN)
    else:
        github_api.call("PATCH", f"issues/comments/{int(sticky['id'])}", {"body": body}, token=APP_TOKEN)


def main(argv: list[str]) -> int:
    steps = {"rerun": rerun, "relay": relay}
    if len(argv) != 2 or argv[1] not in steps:
        print(f"usage: bot_relay.py {'|'.join(steps)}", file=sys.stderr)
        return 2
    steps[argv[1]](bot_tables.Tables())
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
