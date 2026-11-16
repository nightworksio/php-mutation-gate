"""The bot's sticky comment on a pull request: how it is found, and the marks it carries.

One comment per pull request holds what the bot says about it (ADR-0019). It
starts with STICKY, and carries hidden marks, one per line:

- the relay's: `<!-- mutation-gate-bot: run=<id> sha=<sha> -->`, written by
  the App so that its edit starts the explainer;
- the welcome sweep's: `<!-- mutation-gate-bot: welcome sha=<sha> -->`, written
  by the App on a comment it opens for an author new here, so that the
  explainer writes the welcome while CI waits for approval;
- the explainer's: `<!-- mutation-gate-bot: suggested=<sha> -->`, the head
  whose fixes it posted as suggested changes, so it posts them once.

Every value in a mark is a number or a SHA, and is validated again where it
is read. Only a comment written by a bot account
counts: a person's comment that copies the marks is never read.

Two halves: the functions above `find` decide from text alone, and `find`
asks GitHub.
"""
import re

import github_api

STICKY = "<!-- mutation-gate-bot -->"

RELAY = re.compile(r"^<!-- mutation-gate-bot: run=([1-9][0-9]{0,19}) sha=([0-9a-f]{40}) -->$", re.MULTILINE)
WELCOME = re.compile(r"^<!-- mutation-gate-bot: welcome sha=([0-9a-f]{40}) -->$", re.MULTILINE)
SUGGESTED = re.compile(r"^<!-- mutation-gate-bot: suggested=([0-9a-f]{40}) -->$", re.MULTILINE)

# The line the relay writes above the comment while its explainer runs.
EXPLAINING = re.compile(r"^> \*\*Explaining\*\*.*$\n?", re.MULTILINE)

# The line the welcome sweep writes below its mark while the explainer runs.
WELCOMING = re.compile(r"^> \*\*Welcome\.\*\*.*$\n?", re.MULTILINE)


def relay_mark(run: int, sha: str) -> str:
    """The relay's mark for a run of a head."""
    return f"<!-- mutation-gate-bot: run={run} sha={sha} -->"


def welcome_mark(sha: str) -> str:
    """The welcome sweep's mark for a head."""
    return f"<!-- mutation-gate-bot: welcome sha={sha} -->"


def suggested_mark(sha: str) -> str:
    return f"<!-- mutation-gate-bot: suggested={sha} -->"


def relayed(body: str) -> tuple[int, str] | None:
    """The run and head the relay's mark names, or None where the comment has none."""
    found = RELAY.search(body)
    return (int(found[1]), found[2]) if found else None


def welcomed(body: str) -> str:
    """The head the welcome sweep's mark names, or ''."""
    found = WELCOME.search(body)
    return found[1] if found else ""


def suggested(body: str) -> str:
    """The head whose fixes were posted as suggested changes, or ''."""
    found = SUGGESTED.search(body)
    return found[1] if found else ""


def relaying(body: str, mark: str, line: str) -> str:
    """The comment as the relay leaves it: its mark and the explaining line replace the last ones, and the welcome sweep's."""
    rest = body.removeprefix(STICKY)
    for left in (RELAY, EXPLAINING, WELCOME, WELCOMING):
        rest = left.sub("", rest)
    rest = rest.lstrip("\n")
    return f"{STICKY}\n{mark}\n{line}\n\n{rest}".rstrip("\n") + "\n"


def opening(mark: str, line: str) -> str:
    """A new comment holding only a mark and the line under it."""
    return relaying("", mark, line)


def is_sticky(comment: dict) -> bool:
    """Whether a comment is the bot's sticky one: written by a bot account, and starting with the mark."""
    user = comment.get("user") or {}
    body = comment.get("body") or ""
    return user.get("type") == "Bot" and isinstance(body, str) and body.startswith(STICKY)


def find(number: int) -> dict | None:
    """The pull request's sticky comment, the oldest where there are several, or None."""
    for comment in github_api.listed(f"issues/{number}/comments"):
        if is_sticky(comment):
            return comment
    return None
