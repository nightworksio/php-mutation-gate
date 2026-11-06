#!/usr/bin/env python3
"""The explainer, the checklist and the welcome: the sticky comment, written from what GitHub says (ADR-0019).

Usage: bot_explain.py explain|checklist

- `explain` runs on `issue_comment` (edited) where the App's relay set the
  sticky comment to its explaining state. It reads the relay's mark from
  COMMENT_BODY, checks it names a relayed run of the pull request's head, and
  writes the comment over it.
- `checklist` runs on `pull_request_target` (opened, ready for review,
  converted to draft) and writes the comment where it exists, or where the
  pull request's author is new here, which opens it with the welcome.

Neither checks a pull request out, installs or loads it. Its title, body,
files and runs are read through the API as data, its CI's evidence through
bot_evidence, and the files a fix changes as the head's blobs. The comment is
written with the job's own token, so its edit starts no workflow.

Two halves: bot_render decides the text from what `collect` read here.
"""
import base64
import os
import re
import sys

import bot_evidence
import bot_patch
import bot_relay
import bot_render
import bot_sticky
import bot_tables
import github_api

NUMBER = re.compile(r"\A[1-9][0-9]{0,9}\Z")
SHA = re.compile(r"\A[0-9a-f]{40}\Z")
BRANCH = re.compile(r"\A[A-Za-z0-9._/-]{1,255}\Z")
WORD = re.compile(r"\A[a-z_]{1,32}\Z")
ASSOCIATION = re.compile(r"\A[A-Z_]{1,32}\Z")

# The most characters of a title and a body the bot reads, as GitHub caps them.
MOST_TITLE = 256
MOST_BODY = 65536

# The most bytes of a file's head the bot reads to hold a fix to it.
MOST_HEAD = 1024 * 1024


def checked(value, pattern: re.Pattern) -> str:
    if not isinstance(value, str) or not pattern.match(value):
        raise ValueError("GitHub's answer is not of the shape the bot reads")
    return value


def text(value, most: int) -> str:
    return value[:most] if isinstance(value, str) else ""


def web(value) -> str:
    """A URL GitHub answered, held to this repository's pages."""
    prefix = github_api.web_url("")
    if not isinstance(value, str) or not value.startswith(prefix) or not re.fullmatch(r"[A-Za-z0-9._~:/?#=&%+-]+", value):
        raise ValueError("GitHub's answer is not a page of this repository")
    return value


def pull(number: int) -> bot_render.Pull:
    answer = github_api.call("GET", f"pulls/{number}")
    head = answer["head"]
    return bot_render.Pull(
        number=number,
        sha=checked(head["sha"], SHA),
        draft=answer.get("draft") is True,
        association=checked(answer.get("author_association", "NONE"), ASSOCIATION),
        title=text(answer.get("title"), MOST_TITLE),
        body=text(answer.get("body"), MOST_BODY),
        same_repo=((head.get("repo") or {}).get("full_name") == github_api.repository()),
    )


def files(number: int) -> dict[str, list[tuple[int, int]]]:
    """Each file the pull request changes whose name the bot may write, and the head's lines its diff shows."""
    changed = {}
    for item in github_api.listed(f"pulls/{number}/files"):
        name = item.get("filename")
        if isinstance(name, str) and bot_patch.safe_path(name) and item.get("status") != "removed":
            changed[name] = bot_patch.diff_ranges(item.get("patch") or "")
    return changed


def ours(run: dict, number: int) -> bool:
    """Whether a run on the head may be this pull request's: GitHub lists it for this one, or, for a fork's, for none."""
    listed = [item.get("number") for item in run.get("pull_requests") or [] if isinstance(item, dict)]
    return not listed or number in listed


def runs(number: int, sha: str, tables: bot_tables.Tables) -> tuple[dict[str, dict], bool]:
    """GitHub's answer for the latest run of each relayed workflow on the head, and whether one waits for approval."""
    workflows = tables.config["relay"]["workflows"]
    latest: dict[str, dict] = {}
    for run in github_api.listed(f"actions/runs?head_sha={sha}&event=pull_request", "workflow_runs"):
        if not ours(run, number):
            continue
        key = next((key for key, path in workflows.items() if run.get("path") == path), "")
        if key and (key not in latest or int(run["id"]) > int(latest[key]["id"])):
            latest[key] = run
    waiting = any(
        run.get("conclusion") == "action_required" or run.get("status") in ("action_required", "waiting") for run in latest.values()
    )
    return latest, waiting


def summary(key: str, run: dict) -> bot_render.Run:
    conclusion = run.get("conclusion") or ""
    return bot_render.Run(
        key=key,
        id=int(run["id"]),
        status=checked(run.get("status") or "", WORD),
        conclusion=checked(conclusion, WORD) if conclusion else "",
        url=web(run["html_url"]),
    )


def jobs(run: bot_render.Run, tables: bot_tables.Tables) -> list[bot_render.Job]:
    found = []
    for job in github_api.listed(f"actions/runs/{run.id}/jobs?filter=latest", "jobs"):
        name = job.get("name") if isinstance(job.get("name"), str) else ""
        conclusion = job.get("conclusion") or ""
        found.append(bot_render.Job(
            gate=bot_tables.gate_of(name, tables.gates),
            conclusion=checked(conclusion, WORD) if conclusion else "",
            url=web(job["html_url"]),
        ))
    return found


def head_text(path: str, sha: str) -> str | None:
    """A file's text at the head, or None where it is not a text file the bot reads."""
    try:
        answer = github_api.call("GET", f"contents/{'/'.join(github_api.quoted(part) for part in path.split('/'))}?ref={sha}")
    except github_api.Failed:
        return None
    if not isinstance(answer, dict) or answer.get("encoding") != "base64" or int(answer.get("size", MOST_HEAD + 1)) > MOST_HEAD:
        return None
    try:
        return base64.b64decode("".join(str(answer["content"]).split()), validate=True).decode("utf-8")
    except (ValueError, UnicodeDecodeError):
        return None


def comments(number: int, tables: bot_tables.Tables) -> tuple[dict | None, str]:
    """The sticky comment, and the page of the gate's own comment."""
    sticky, gate = None, ""
    marker = tables.config["comment"]["gate_marker"]
    for comment in github_api.listed(f"issues/{number}/comments"):
        if bot_sticky.is_sticky(comment):
            sticky = sticky or comment
            continue
        body = comment.get("body") or ""
        if not gate and (comment.get("user") or {}).get("type") == "Bot" and isinstance(body, str) and marker in body:
            gate = web(comment.get("html_url"))
    return sticky, gate


def reran(run: dict, tables: bot_tables.Tables) -> list[bot_render.Reran]:
    """The jobs of a run the relay re-ran for the infrastructure, by the name main's gates give them."""
    if run.get("run_attempt") == 1 and run.get("status") == "completed":
        return []
    found = []
    for job_id, kind in bot_relay.reran_of(run, tables.config["rerun"]):
        job = github_api.call("GET", f"actions/jobs/{int(job_id)}")
        gate = bot_tables.gate_of(job.get("name") if isinstance(job.get("name"), str) else "", tables.gates)
        name = bot_render.check_name(tables.gates[gate]) if gate else tables.say("a-job")
        found.append(bot_render.Reran(name=name, url=web(job["html_url"]), kind=kind))
    return found


def collect(number: int, tables: bot_tables.Tables) -> tuple[bot_render.State, dict | None]:
    pulled = pull(number)
    branch = checked(os.environ.get("GITHUB_REF_NAME", ""), BRANCH)
    latest, waiting = runs(number, pulled.sha, tables)
    found: dict[str, bot_render.Run | None] = {key: None for key in tables.config["relay"]["workflows"]}
    found |= {key: summary(key, run) for key, run in latest.items()}
    state = bot_render.State(pull=pulled, files=files(number), runs=found, waiting=waiting, blob=github_api.web_url(f"blob/{branch}"))
    names = bot_evidence.expected(tables.gates)
    for key, run in found.items():
        if run is None:
            continue
        state.jobs[key] = jobs(run, tables)
        state.reran.extend(reran(latest[key], tables))
        if run.status == "completed":
            evidence, fixes = bot_evidence.read(bot_evidence.fetch(run.id, names, tables.config["evidence"]), names, tables.tools_of)
            state.findings.update(evidence)
            state.fixes.update(fixes)
    for gate, patches in state.fixes.items():
        for patch in patches:
            if bot_render.fixable(gate, patch, state, tables) and patch.path not in state.heads:
                head = head_text(patch.path, pulled.sha)
                if head is not None:
                    state.heads[patch.path] = head
    sticky, state.gate_comment = comments(number, tables)
    state.suggested = bot_sticky.suggested(sticky["body"]) if sticky else ""
    return state, sticky


def write(state: bot_render.State, sticky: dict | None, tables: bot_tables.Tables) -> None:
    inside, outside = bot_render.split_fixes(state, tables)
    suggested = state.suggested if state.suggested == state.pull.sha else ""
    if inside and not suggested:
        key = "suggestions-review" if state.pull.same_repo else "suggestions-review-fork"
        try:
            github_api.call("POST", f"pulls/{state.pull.number}/reviews", {
                "commit_id": state.pull.sha,
                "event": "COMMENT",
                "body": tables.say(key),
                "comments": [_review_comment(suggestion) for suggestion in inside],
            })
            suggested = state.pull.sha
        except github_api.Failed as failed:
            if failed.status != 422:
                raise
    body = bot_render.render(state, tables, outside, suggested)
    if sticky is None:
        github_api.comment(state.pull.number, body)
    else:
        github_api.call("PATCH", f"issues/comments/{int(sticky['id'])}", {"body": body})


def _review_comment(suggestion: bot_patch.Suggestion) -> dict:
    comment = {"path": suggestion.path, "line": suggestion.end, "side": "RIGHT", "body": bot_render.suggestion_body(suggestion)}
    if suggestion.start != suggestion.end:
        comment |= {"start_line": suggestion.start, "start_side": "RIGHT"}
    return comment


def explain(tables: bot_tables.Tables) -> None:
    """Write the comment over the relay's explaining state, where its mark names a relayed run of the head."""
    mark = bot_sticky.relayed(os.environ.get("COMMENT_BODY", ""))
    if mark is None:
        return
    run_id, sha = mark
    number = int(checked(os.environ.get("NUMBER", ""), NUMBER))
    run = github_api.call("GET", f"actions/runs/{run_id}")
    if run.get("head_sha") != sha or run.get("path") not in tables.config["relay"]["workflows"].values():
        return
    state, sticky = collect(number, tables)
    if state.pull.sha != sha or sticky is None:
        return
    write(state, sticky, tables)


def checklist(tables: bot_tables.Tables) -> None:
    """Write the comment where it exists, or open it with the welcome for an author new here."""
    number = int(checked(os.environ.get("NUMBER", ""), NUMBER))
    state, sticky = collect(number, tables)
    if sticky is None and state.pull.association not in tables.config["checklist"]["welcomed"]:
        return
    write(state, sticky, tables)


def main(argv: list[str]) -> int:
    steps = {"explain": explain, "checklist": checklist}
    if len(argv) != 2 or argv[1] not in steps:
        print(f"usage: bot_explain.py {'|'.join(steps)}", file=sys.stderr)
        return 2
    steps[argv[1]](bot_tables.Tables())
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
