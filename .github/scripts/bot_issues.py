#!/usr/bin/env python3
"""Duplicate issues, matched by the message slugs their bodies name (ADR-0019).

Usage: bot_issues.py

Runs on `issues` (opened, edited). The body reaches it through ISSUE_BODY
alone, and only the slugs found in it by pattern that are sections of main's
troubleshooting guide are kept (ADR-0018). Its one reply lists the other
issues that name the same slug, open and closed, and links each slug's
section. It is edited in place when the body is, so an issue gets one reply.

Two halves: the functions above `main` decide from text alone, and `main`
asks GitHub.
"""
import os
import re
import sys

import bot_tables
import github_api

NUMBER = re.compile(r"\A[1-9][0-9]{0,9}\Z")

# A slug as the gate prints its troubleshooting link, or as a person copies it.
SLUG = re.compile(r"troubleshooting(?:\.md)?/?#([a-z0-9]+(?:-[a-z0-9]+)*)")

BOT = "github-actions[bot]"
MARK = "<!-- mutation-gate-bot: duplicates -->"


def slugs_in(body: str, known: set[str], most: int) -> list[str]:
    """The slugs of main's troubleshooting guide the body names, in order, at most `most`."""
    found = []
    for slug in SLUG.findall(body):
        if slug in known and slug not in found:
            found.append(slug)
    return found[:most]


def reply(numbers: list[int], slugs: list[str], tables: bot_tables.Tables, blob: str) -> str:
    """The reply: the other issues by number, and each slug's section."""
    words = tables.words["duplicates"]
    issues = "\n".join(bot_tables.fill(words["issue"], {"number": str(number)}) for number in numbers)
    sections = ", ".join(
        bot_tables.fill(words["section"], {"slug": slug, "link": f"{blob}/{bot_tables.TROUBLESHOOTING.as_posix()}#{slug}"})
        for slug in slugs
    )
    return f"{MARK}\n" + bot_tables.fill(words["found"], {"issues": issues, "sections": sections})


def others(number: int, slugs: list[str], known: set[str], most: int) -> list[int]:
    """The other issues whose bodies name one of the slugs, open and closed, newest first."""
    found: set[int] = set()
    for slug in slugs:
        for item in github_api.search_issues(f'repo:{github_api.repository()} is:issue "{slug}"', most):
            named = slugs_in(item.get("body") or "", known, len(known)) if isinstance(item.get("body"), str) else []
            if isinstance(item.get("number"), int) and item["number"] != number and slug in named:
                found.add(item["number"])
    return sorted(found, reverse=True)[:most]


def main(argv: list[str]) -> int:
    tables = bot_tables.Tables()
    limits = tables.config["duplicates"]
    number_text = os.environ.get("NUMBER", "")
    if not NUMBER.match(number_text):
        raise ValueError("NUMBER is not an issue's number")
    number = int(number_text)
    slugs = slugs_in(os.environ.get("ISSUE_BODY", ""), tables.slugs, limits["most_slugs"])
    if not slugs:
        return 0
    numbers = others(number, slugs, tables.slugs, limits["most_issues"])
    if not numbers:
        return 0
    branch = os.environ.get("GITHUB_REF_NAME", "")
    if not re.match(r"\A[A-Za-z0-9._/-]{1,255}\Z", branch):
        raise ValueError("GITHUB_REF_NAME is not a branch")
    body = reply(numbers, slugs, tables, github_api.web_url(f"blob/{branch}"))
    mine = next(
        (item for item in github_api.listed(f"issues/{number}/comments") if (item.get("user") or {}).get("login") == BOT and MARK in (item.get("body") or "")),
        None,
    )
    if mine is None:
        github_api.comment(number, body)
    elif mine["body"] != body:
        github_api.call("PATCH", f"issues/comments/{int(mine['id'])}", {"body": body})
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
