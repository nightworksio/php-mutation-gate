"""The GitHub REST and GraphQL APIs, as the bot's scripts call them with a job's own token.

The I/O half every bot script shares: a request is a method, a path built
only from validated values, and a JSON body. The repository and the API come
from the runner's environment, and the token from GH_TOKEN, or from the
environment variable a call names: APP_TOKEN holds the App's.
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

# The most pages of 100 a listing is read to.
MAX_PAGES = 30

# The token every call uses unless it names another.
TOKEN = "GH_TOKEN"


class Failed(Exception):
    """A request GitHub answered with an error status."""

    def __init__(self, status: int):
        super().__init__(f"GitHub answered {status}")
        self.status = status


class _Unauthorised(urllib.request.HTTPRedirectHandler):
    """A redirect followed without the token: an artifact's archive is served from storage, not from GitHub."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        request = super().redirect_request(req, fp, code, msg, headers, newurl)
        request.remove_header("Authorization")
        return request


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


def _headers(token: str) -> dict:
    return {
        "Accept": "application/vnd.github+json",
        "Authorization": f"Bearer {os.environ[token]}",
        "X-GitHub-Api-Version": "2022-11-28",
    }


def _read(request: urllib.request.Request, opener=None) -> bytes:
    try:
        with (opener.open if opener else urllib.request.urlopen)(request, timeout=30) as response:
            text = response.read(MAX_RESPONSE + 1)
    except urllib.error.HTTPError as error:
        raise Failed(error.code) from None
    if len(text) > MAX_RESPONSE:
        raise ValueError("GitHub's answer is larger than the bot reads")
    return text


def call(method: str, path: str, body: dict | None = None, token: str = TOKEN):
    """The JSON GitHub answers for `/repos/<this repository>/<path>`, or None for no content."""
    request = urllib.request.Request(
        f"{os.environ.get('GITHUB_API_URL', 'https://api.github.com')}/repos/{repository()}/{path}",
        data=None if body is None else json.dumps(body).encode(),
        method=method,
        headers=_headers(token),
    )
    text = _read(request)
    return json.loads(text) if text.strip() else None


def listed(path: str, field: str = "", token: str = TOKEN) -> list:
    """Every item of a listing, page by page: the answer itself, or its `field`."""
    items = []
    joiner = "&" if "?" in path else "?"
    for page in range(1, MAX_PAGES + 1):
        answer = call("GET", f"{path}{joiner}per_page=100&page={page}", token=token)
        found = answer[field] if field else answer
        items.extend(found)
        if len(found) < 100:
            break
    return items


def search_issues(query: str, most: int, token: str = TOKEN) -> list:
    """The issues and pull requests GitHub's search finds for a query, at most `most`."""
    request = urllib.request.Request(
        f"{os.environ.get('GITHUB_API_URL', 'https://api.github.com')}/search/issues?q={quoted(query)}&per_page={int(most)}",
        method="GET",
        headers=_headers(token),
    )
    return json.loads(_read(request)).get("items") or []


def graphql(query: str, variables: dict, token: str = TOKEN) -> dict:
    """The data GitHub's GraphQL API answers; an answer with errors is raised."""
    request = urllib.request.Request(
        os.environ.get("GITHUB_GRAPHQL_URL", "https://api.github.com/graphql"),
        data=json.dumps({"query": query, "variables": variables}).encode(),
        method="POST",
        headers=_headers(token),
    )
    answer = json.loads(_read(request))
    if answer.get("errors"):
        raise Failed(422)
    return answer["data"]


def archive(artifact: int, token: str = TOKEN) -> bytes:
    """An artifact's zip archive, at most MAX_RESPONSE bytes, fetched from storage without the token."""
    request = urllib.request.Request(
        f"{os.environ.get('GITHUB_API_URL', 'https://api.github.com')}/repos/{repository()}/actions/artifacts/{int(artifact)}/zip",
        method="GET",
        headers=_headers(token),
    )
    return _read(request, urllib.request.build_opener(_Unauthorised))


def comment(number: int, body: str) -> None:
    """Post a comment on an issue or a pull request."""
    call("POST", f"issues/{number}/comments", {"body": body})
