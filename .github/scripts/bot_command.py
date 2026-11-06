#!/usr/bin/env python3
"""The commands a pull request's comments run (ADR-0019).

Usage: bot_command.py decide|refuse|update|retest|fix|docs

`/docs` answers on issues and pull requests, and the rest on pull requests
only. Every comment is read as hostile. Its body reaches this script through the
environment only, and only its first line is read, as one of the commands
below. Who may run a command is never read from the comment: the pull
request's author is matched by user id from the API, and anyone else must
hold write, maintain or admin on this repository by the collaborator
permission endpoint. Every reply is a text from .github/bot/commands.json,
filled with values validated here. `/fix` commits as the App, through
bot_fix, which says what it holds the fix to.

Two halves: the functions above `main` decide from values alone, and the
functions below them ask GitHub, and write the job's outputs and replies.
"""
import json
import os
import re
import sys
from pathlib import Path

import bot_docs
import bot_evidence
import bot_explain
import bot_fix
import bot_patch
import bot_tables
import github_api

# What each command does is in ADR-0019's table. `/docs` may be run by anyone.
COMMANDS = ("update", "rebase", "retest", "fix", "docs")
ANYONE = ("docs",)

# The collaborator permission that may run the rest: `write` also stands for
# maintain, and for a custom role built on write, in the endpoint's answer.
MAY_WRITE = ("admin", "write")

# The most re-runs `/retest` asks for on one head.
RETESTS = 3

# The conclusions `/retest` re-runs the failed jobs of.
FAILED = ("failure", "cancelled", "timed_out")

# The first line of a comment: a command, and for `/docs` the words it asks with.
LINE = re.compile(r"\A/([a-z]+)(?:[ \t]+([^\r\n]*?))?[ \t]*(?:\r?\n|\Z)")

NUMBER = re.compile(r"\A[1-9][0-9]{0,9}\Z")
LOGIN = re.compile(r"\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\Z")
SHA = re.compile(r"\A[0-9a-f]{40}\Z")
BRANCH = re.compile(r"\A[A-Za-z0-9._/-]{1,255}\Z")

# The one account that writes the bot's replies, and the mark a refusal carries.
BOT = "github-actions[bot]"
REFUSED = "<!-- mutation-gate-bot: refused user={user} command={command} -->"

TABLE = Path(".github/bot/commands.json")
DOCS = Path(".docs")

# Every reply this script sends, by its key in the table.
REPLIES = (
    "refused",
    "closed",
    "update-asked",
    "rebase-is-update",
    "update-current",
    "update-refused",
    "update-forbidden",
    "retest-asked",
    "retest-none",
    "retest-waiting",
    "retest-running",
    "retest-green",
    "retest-spent",
    "fix-committed",
    "fix-nothing",
    "fix-fork",
    "fix-running",
    "fix-refused",
    "fix-moved",
    "fix-protected",
    "fix-unmatched",
    "fix-commit-body",
    "why-not-in-diff",
    "why-never",
    "why-too-large",
    "why-stale",
    "docs-found",
    "docs-none",
)

PLACEHOLDER = re.compile(r"\{([a-z]+)\}")


def parse(body: str) -> tuple[str, str] | None:
    """The command the comment's first line runs, and the words `/docs` asks with."""
    match = LINE.match(body)
    if match is None or match[1] not in COMMANDS:
        return None
    words = match[2] or ""
    if words and match[1] not in ANYONE:
        return None
    return match[1], words


def may_run(command: str, commenter: int, author: int, permission: str) -> bool:
    """Whether the commenter may run the command: anyone for `/docs`, else the author or a writer."""
    return command in ANYONE or commenter == author or permission in MAY_WRITE


def fill(template: str, values: dict[str, str]) -> str:
    """A reply's text with every placeholder filled; a missing value is an error."""
    return PLACEHOLDER.sub(lambda match: values[match[1]], template)


def update_reply(state: str, behind: int) -> str:
    """Which reply `/update` gives before asking GitHub, or none where it asks."""
    if state != "open":
        return "closed"
    return "update-current" if behind == 0 else ""


def retest_reply(run: dict | None) -> str:
    """Which reply `/retest` gives for the latest CI run on the head; `retest-asked` re-runs it."""
    if run is None:
        return "retest-none"
    if run["conclusion"] == "action_required" or run["status"] in ("action_required", "waiting"):
        return "retest-waiting"
    if run["status"] != "completed":
        return "retest-running"
    if run["conclusion"] not in FAILED:
        return "retest-green"
    return "retest-spent" if run["run_attempt"] > RETESTS else "retest-asked"


def docs_reply(found: list[tuple[str, str, str]], blob: str) -> tuple[str, str]:
    """The `/docs` reply and its links, one per heading found."""
    if not found:
        return "docs-none", ""
    return "docs-found", "\n".join(f"- [{text}]({blob}/{path}#{slug})" for path, text, slug in found)


def _env(name: str, pattern: re.Pattern) -> str:
    value = os.environ.get(name, "")
    if not pattern.match(value):
        raise ValueError(f"{name} is not what GitHub sends")
    return value


def _number() -> int:
    return int(_env("NUMBER", NUMBER))


def _command(allowed: tuple[str, ...]) -> str:
    command = os.environ.get("COMMAND", "")
    if command not in allowed:
        raise ValueError("COMMAND is not a command this job runs")
    return command


def _reply(number: int, keys: list[str], values: dict[str, str], marker: str = "") -> None:
    table = json.loads(TABLE.read_text(encoding="utf-8"))
    texts = [fill(table["replies"][key], {**values, "who": table["who"]}) for key in keys]
    github_api.comment(number, "\n\n".join([*texts, marker] if marker else texts))


def _pull(number: int) -> dict:
    pull = github_api.call("GET", f"pulls/{number}")
    _checked(pull["head"]["sha"], SHA)
    _checked(pull["base"]["ref"], BRANCH)
    return pull


def _checked(value, pattern: re.Pattern) -> str:
    if not isinstance(value, str) or not pattern.match(value):
        raise ValueError("GitHub's answer is not of the shape the bot reads")
    return value


def _output(values: dict[str, str]) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
        for name, value in values.items():
            output.write(f"{name}={value}\n")


def decide() -> None:
    """The command a comment runs and whether its author may: the job's outputs."""
    parsed = parse(os.environ.get("COMMENT_BODY", ""))
    if parsed is None or (parsed[0] not in ANYONE and os.environ.get("ON_PULL_REQUEST") != "true"):
        _output({"command": "", "allowed": "false", "words": ""})
        return

    command, words = parsed
    commenter = int(_env("COMMENTER_ID", NUMBER))
    allowed = command in ANYONE
    if not allowed:
        author = github_api.call("GET", f"pulls/{_number()}")["user"]["id"]
        permission = "" if commenter == author else _permission(_env("COMMENTER_LOGIN", LOGIN))
        allowed = may_run(command, commenter, author, permission)

    _output({"command": command, "allowed": "true" if allowed else "false", "words": " ".join(bot_docs.tokens(words))})


def _permission(login: str) -> str:
    try:
        return github_api.call("GET", f"collaborators/{github_api.quoted(login)}/permission")["permission"]
    except github_api.Failed as failed:
        if failed.status == 404:
            return ""
        raise


def refuse() -> None:
    """One reply per commenter and command, naming who may run it."""
    number, command = _number(), _command(tuple(name for name in COMMANDS if name not in ANYONE))
    marker = fill(REFUSED, {"user": str(int(_env("COMMENTER_ID", NUMBER))), "command": command})
    for page in range(1, 31):
        comments = github_api.call("GET", f"issues/{number}/comments?per_page=100&page={page}")
        if any(item["user"]["login"] == BOT and marker in item["body"] for item in comments):
            return
        if len(comments) < 100:
            break
    _reply(number, ["refused"], {"command": command}, marker)


def update() -> None:
    """Ask GitHub to merge the base into the head, at the head the bot read."""
    number, command = _number(), _command(("update", "rebase"))
    pull = _pull(number)
    base, head = pull["base"]["ref"], pull["head"]["sha"]
    behind = 0
    if pull["state"] == "open":
        behind = int(github_api.call("GET", f"compare/{base}...{head}")["behind_by"])
    key = update_reply(pull["state"], behind)
    if not key:
        try:
            github_api.call("PUT", f"pulls/{number}/update-branch", {"expected_head_sha": head})
            key = "update-asked"
        except github_api.Failed as failed:
            if failed.status not in (403, 422):
                raise
            key = "update-forbidden" if failed.status == 403 else "update-refused"
    keys = ["rebase-is-update", key] if command == "rebase" and key != "closed" else [key]
    _reply(number, keys, {"base": base, "command": command})


def retest() -> None:
    """Re-run the failed jobs of the latest CI run on the head, within the limit."""
    number = _number()
    pull = _pull(number)
    runs = github_api.call(
        "GET",
        f"actions/workflows/ci.yml/runs?event=pull_request&head_sha={pull['head']['sha']}&per_page=1",
    )["workflow_runs"]
    run = runs[0] if runs else None
    key = "closed" if pull["state"] != "open" else retest_reply(run)
    values = {"command": "retest", "limit": str(RETESTS)}
    if run is not None:
        run_id = int(run["id"])
        values |= {"run": github_api.web_url(f"actions/runs/{run_id}"), "attempt": str(int(run["run_attempt"]))}
        if key == "retest-asked":
            github_api.call("POST", f"actions/runs/{run_id}/rerun-failed-jobs")
    _reply(number, [key], values)


def _ci_run(sha: str, tables: bot_tables.Tables) -> dict | None:
    workflow = Path(tables.config["relay"]["workflows"]["ci"]).name
    runs = github_api.call("GET", f"actions/workflows/{workflow}/runs?event=pull_request&head_sha={sha}&per_page=1")["workflow_runs"]
    return runs[0] if runs else None


def fix() -> None:
    """Commit the fix the pull request's own latest CI run computed, as the App, to its own branch alone."""
    number = _number()
    tables = bot_tables.Tables()
    pull = _pull(number)
    values = {"command": "fix"}
    key = _fix_target(number, pull, tables, values)
    _reply(number, [key], values)


def _fix_target(number: int, pull: dict, tables: bot_tables.Tables, values: dict[str, str]) -> str:
    """The reply where the branch or the run is not one the App may act on; else what `_fix` does."""
    if pull["state"] != "open":
        return "closed"
    head = pull["head"]
    if (head.get("repo") or {}).get("full_name") != github_api.repository():
        return "fix-fork"
    ref = _checked(head["ref"], BRANCH)
    default = _checked(pull["base"]["repo"]["default_branch"], BRANCH)
    protected = github_api.call("GET", f"branches/{github_api.quoted(ref)}").get("protected") is True
    rules = github_api.listed(f"rules/branches/{github_api.quoted(ref)}")
    if bot_fix.off_limits(ref, pull["base"]["ref"], default, protected, rules, tables.config["fix"]["protecting_rules"]):
        return "fix-protected"
    run = _ci_run(head["sha"], tables)
    if run is None or run.get("status") != "completed":
        return "fix-running"
    if not bot_fix.own_run(run, ref, head["sha"], number):
        return "fix-unmatched"
    run_id = int(run["id"])
    values["run"] = github_api.web_url(f"actions/runs/{run_id}")
    return _fix(number, pull, ref, run_id, tables, values)


def _fix(number: int, pull: dict, ref: str, run_id: int, tables: bot_tables.Tables, values: dict[str, str]) -> str:
    names = bot_evidence.expected(tables.gates)
    _, fixes = bot_evidence.read(bot_evidence.fetch(run_id, names, tables.config["evidence"]), names, tables.tools_of)
    if not fixes:
        return "fix-nothing"
    sha = pull["head"]["sha"]
    size = sum(len(bot_patch.render(patch)) for patches in fixes.values() for patch in patches)
    why = bot_fix.refusal(fixes, set(bot_explain.files(number)), tables.config["fix"], size)
    texts: dict[str, str] = {}
    if not why:
        heads = {patch.path: bot_explain.head_text(patch.path, sha) for patches in fixes.values() for patch in patches}
        try:
            texts = bot_fix.applied(fixes, heads)
        except bot_patch.Refused:
            why = "why-stale"
    if why:
        table = json.loads(TABLE.read_text(encoding="utf-8"))["replies"]
        values |= {"why": table[why], "command": " && ".join(tables.gates[gate]["fix"] for gate in sorted(fixes))}
        return "fix-refused"
    if not texts:
        return "fix-nothing"
    table = json.loads(TABLE.read_text(encoding="utf-8"))["replies"]
    body = fill(table["fix-commit-body"], {"run": values["run"], "sha": sha})
    try:
        made = bot_fix.commit(ref, sha, texts, tables.config["fix"]["headline"], body)
    except github_api.Failed:
        return "fix-moved"
    values["commit"] = bot_explain.web(made["url"])
    return "fix-committed"


def docs() -> None:
    """Reply with the headings of `.docs` the words name, as links into main."""
    asked = bot_docs.tokens(os.environ.get("WORDS", ""))
    branch = _env("GITHUB_REF_NAME", BRANCH)
    key, links = docs_reply(bot_docs.search(bot_docs.index(DOCS), asked), github_api.web_url(f"blob/{branch}"))
    _reply(_number(), [key], {"links": links, "docs": github_api.web_url(f"tree/{branch}/{DOCS}")})


def main(argv: list[str]) -> int:
    steps = {"decide": decide, "refuse": refuse, "update": update, "retest": retest, "fix": fix, "docs": docs}
    if len(argv) != 2 or argv[1] not in steps:
        print(f"usage: bot_command.py {'|'.join(steps)}", file=sys.stderr)
        return 2
    steps[argv[1]]()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
