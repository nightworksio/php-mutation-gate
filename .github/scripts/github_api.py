"""The GitHub REST API, as the bot's scripts call it with the job's own token.

The I/O half every bot script shares: a request is a method, a path built
only from validated values, and a JSON body. The repository and the API come
from the runner's environment, and the token from GH_TOKEN.
"""
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

# GITHUB_REPOSITORY, as GitHub writes an owner and a name; a name is never `.` or `..`.
REPOSITORY = re.compile(r"\A[A-Za-z0-9][A-Za-z0-9-]{0,38}/(?!\.{1,2}\Z)[A-Za-z0-9_.-]{1,100}\Z")

# The most of a response the bot reads.
MAX_RESPONSE = 5 * 1024 * 1024


class Failed(Exception):
    """A request GitHub answered with an error status."""

    def __init__(self, status: int):
        super().__init__(f"GitHub answered {status}")
        self.status = status


def repository() -> str:
    """The repository the workflow runs in, validated."""
    name = os.environ.get("GITHUB_REPOSITORY", "")
    if not REPOSITORY.match(name):
        raise ValueError("GITHUB_REPOSITORY is not an owner and a name")
    return name


def web_url(path: str) -> str:
    """A page of this repository on GitHub, such as `actions/runs/1`."""
    return f"{os.environ.get('GITHUB_SERVER_URL', 'https://github.com')}/{repository()}/{path}"


def quoted(value: str) -> str:
    """A value placed in a path, with nothing left that ends a segment."""
    return urllib.parse.quote(value, safe="")


def call(method: str, path: str, body: dict | None = None):
    """The JSON GitHub answers for `/repos/<this repository>/<path>`, or None for no content."""
    request = urllib.request.Request(
        f"{os.environ.get('GITHUB_API_URL', 'https://api.github.com')}/repos/{repository()}/{path}",
        data=None if body is None else json.dumps(body).encode(),
        method=method,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {os.environ['GH_TOKEN']}",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            text = response.read(MAX_RESPONSE + 1)
    except urllib.error.HTTPError as error:
        raise Failed(error.code) from None
    if len(text) > MAX_RESPONSE:
        raise ValueError("GitHub's answer is larger than the bot reads")
    return json.loads(text) if text.strip() else None


def comment(number: int, body: str) -> None:
    """Post a comment on an issue or a pull request."""
    call("POST", f"issues/{number}/comments", {"body": body})
