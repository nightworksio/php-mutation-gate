#!/usr/bin/env python3
"""The jobs a push to the default branch still runs (ADR-0122).

A push whose tree is the head of the pull request it merged has already had
each gate that pull request ran on that very tree, under the same workflow, so
it drops every one of them that passed there. A job the pull request's scope
skipped runs, and so does every job where GitHub cannot say.

Usage: proven_push.py, with `JOBS` the JSON list of jobs `scope.py` chose,
`GITHUB_REPOSITORY`, `GITHUB_SHA` and `GH_TOKEN`; prints the `jobs` output.
"""
import json
import os
import subprocess

# The jobs a push may drop, each by the checks that say it passed. Mutation
# judges by its own proofs, a job whose matrix a pull request narrows did not
# run whole there, and SonarCloud and mutation read the map `tests` writes, so
# none of those is here.
CHECKS = {
    "site": ("site / build",),
    "alone": ("test files alone",),
    "guards": ("rules", "the rules refuse violations"),
    "checks": ("checks",),
    "lowest": ("lowest dependencies",),
    "phar": ("phar / phar",),
}


def still_running(jobs: list[str], concluded: dict[str, str]) -> list[str]:
    """Of these jobs, those the run of the identical tree did not pass, by each check's conclusion there."""
    def passed(job: str) -> bool:
        checks = CHECKS.get(job, ())
        return checks != () and all(concluded.get(check) == "success" for check in checks)

    return [job for job in jobs if not passed(job)]


def identical_head(pulls: list[dict], tree_of) -> str:
    """The head of the merged pull request whose tree is the pushed one, by what `tree_of` says; none where none is."""
    pushed = tree_of(os.environ.get("GITHUB_SHA", ""))
    for pull in pulls:
        head = (pull.get("head") or {}).get("sha", "")
        if pull.get("merged_at") and head and pushed and tree_of(head) == pushed:
            return head
    return ""


def _api(path: str) -> str:
    """What the API said; nothing where it failed, which keeps every job."""
    asked = subprocess.run(["gh", "api", path], capture_output=True, text=True, check=False, timeout=120)
    return asked.stdout if asked.returncode == 0 else ""


def _loaded(path: str) -> dict | list:
    said = _api(path)
    return json.loads(said) if said else {}


def concluded_at(repository: str, head: str) -> dict[str, str]:
    """Each check's conclusion in the newest pull request run of CI at this head; none where there is none."""
    runs = _loaded(f"repos/{repository}/actions/workflows/ci.yml/runs?head_sha={head}&event=pull_request&per_page=1")
    found = runs.get("workflow_runs", []) if isinstance(runs, dict) else []
    if not found:
        return {}
    listed = _loaded(f"repos/{repository}/actions/runs/{found[0]['id']}/jobs?per_page=100")
    jobs = listed.get("jobs", []) if isinstance(listed, dict) else []
    return {job.get("name", ""): job.get("conclusion", "") for job in jobs}


def main() -> int:
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    jobs = json.loads(os.environ.get("JOBS", "[]"))
    pulls = _loaded(f"repos/{repository}/commits/{os.environ.get('GITHUB_SHA', '')}/pulls")

    def tree_of(commit: str) -> str:
        answer = _loaded(f"repos/{repository}/git/commits/{commit}")
        return answer.get("tree", {}).get("sha", "") if isinstance(answer, dict) else ""

    head = identical_head(pulls if isinstance(pulls, list) else [], tree_of)
    print(f"jobs={json.dumps(still_running(jobs, concluded_at(repository, head)) if head else jobs)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
