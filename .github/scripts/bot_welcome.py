#!/usr/bin/env python3
"""The welcome sweep: the sticky comment opened, as the App, for an author new here while CI waits for approval (ADR-0019).

Usage: bot_welcome.py find|welcome

A first pull request's CI waits for a maintainer to approve it, so no
`workflow_run` fires for the relay to hand on. On a schedule, these steps
handle numbers alone, as the relay does: each open pull request's number, head
SHA, author association and account type, and whether a bot's comment on it
starts with the sticky mark and carries the sweep's. They read no string a
pull request controls into anything they write, check nothing out but main
and install nothing.

- `find`, with the job's token: the open pull requests, newest first and at
  most `checklist.sweep.most_pulls`, whose author `checklist.welcomed` names,
  and which have no sticky comment, or one whose welcome mark names an older
  head. It writes `pulls=<number>,...` to $GITHUB_OUTPUT.
- `welcome`, with the App's token in APP_TOKEN: it reads each number in PULLS
  again, and where the pull request is still one to welcome, opens the sticky
  comment with `<!-- mutation-gate-bot: welcome sha=<sha> -->` and the
  welcoming line, or marks the comment again at the new head. The App's
  comment starts the explainer, on `issue_comment`, which writes the welcome
  and the checklist with the job's own token.

Two halves: `welcomes`, `head_to_mark` and `parse_pulls` decide from what
they are given, and the steps below ask GitHub.
"""
import os
import re
import sys

import bot_sticky
import bot_tables
import github_api

NUMBER = re.compile(r"\A[1-9][0-9]{0,9}\Z")
SHA = re.compile(r"\A[0-9a-f]{40}\Z")

APP_TOKEN = "APP_TOKEN"

# The account type GitHub gives a person; a bot's pull request is not welcomed.
PERSON = "User"


def welcomes(pull: dict, welcomed: list[str]) -> bool:
    """Whether an open pull request is a person's whose author main's config welcomes."""
    return pull.get("state") == "open" and (pull.get("user") or {}).get("type") == PERSON and pull.get("author_association") in welcomed


def head_to_mark(pull: dict, sticky: dict | None) -> str:
    """The head to mark the welcome at: where there is no sticky comment, or its welcome names an older head; '' where there is none to mark."""
    head = (pull.get("head") or {}).get("sha")
    if not isinstance(head, str) or not SHA.match(head):
        raise ValueError("GitHub's answer is not of the shape the bot reads")
    if sticky is None:
        return head
    marked = bot_sticky.welcomed(sticky["body"])
    return head if marked and marked != head else ""


def parse_pulls(text: str, most: int) -> list[int]:
    """What `find` wrote, validated again: at most `most` pull request numbers."""
    numbers = [part for part in text.split(",") if part]
    if len(numbers) > most or not all(NUMBER.match(number) for number in numbers):
        raise ValueError("PULLS is not a list of pull request numbers")
    return [int(number) for number in numbers]


def number_of(pull: dict) -> int:
    number = pull.get("number")
    if not isinstance(number, int) or not NUMBER.match(str(number)):
        raise ValueError("GitHub's answer is not of the shape the bot reads")
    return number


def find(tables: bot_tables.Tables) -> None:
    checklist = tables.config["checklist"]
    most = int(checklist["sweep"]["most_pulls"])
    found = []
    for pull in github_api.call("GET", f"pulls?state=open&sort=created&direction=desc&per_page={most}")[:most]:
        number = number_of(pull)
        if welcomes(pull, checklist["welcomed"]) and head_to_mark(pull, bot_sticky.find(number)):
            found.append(number)
    github_api.output({"pulls": ",".join(str(number) for number in found)})


def welcome(tables: bot_tables.Tables) -> None:
    checklist = tables.config["checklist"]
    for number in parse_pulls(os.environ.get("PULLS", ""), int(checklist["sweep"]["most_pulls"])):
        pull = github_api.call("GET", f"pulls/{number}")
        if not welcomes(pull, checklist["welcomed"]):
            continue
        sticky = bot_sticky.find(number)
        head = head_to_mark(pull, sticky)
        if not head:
            continue
        body = bot_sticky.opening(bot_sticky.welcome_mark(head), tables.say("welcoming", sha=head[:7]))
        if sticky is None:
            github_api.call("POST", f"issues/{number}/comments", {"body": body}, token=APP_TOKEN)
        else:
            github_api.call("PATCH", f"issues/comments/{int(sticky['id'])}", {"body": body}, token=APP_TOKEN)


def main(argv: list[str]) -> int:
    steps = {"find": find, "welcome": welcome}
    if len(argv) != 2 or argv[1] not in steps:
        print(f"usage: bot_welcome.py {'|'.join(steps)}", file=sys.stderr)
        return 2
    steps[argv[1]](bot_tables.Tables())
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
