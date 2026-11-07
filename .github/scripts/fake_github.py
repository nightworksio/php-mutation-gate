"""GitHub as the bot's tests answer for it: answers by method and path, and every request kept.

A path is matched whole first, query included, and then without its query.
An answer that is an exception is raised, and a list of answers is given one
per request, the last one again once the rest are spent.
"""
import contextlib
import os
import tempfile
from pathlib import Path
from unittest import mock

import github_api

ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "nightworksio/php-mutation-gate"
WEB = f"https://github.com/{REPOSITORY}"


class GitHub:
    def __init__(self, answers: dict | None = None):
        self.answers = dict(answers or {})
        self.asked: list[tuple[str, str, dict | None, str]] = []
        self.archives: dict[int, bytes] = {}
        self.queries: list[tuple[dict, str]] = []
        self.graphql_answer: dict | Exception = {}
        self.found: dict[str, list] = {}

    def _answer(self, method: str, path: str):
        for key in ((method, path), (method, path.split("?")[0])):
            if key in self.answers:
                answer = self.answers[key]
                if isinstance(answer, Sequence):
                    answer = answer.pop(0) if len(answer) > 1 else answer[0]
                if isinstance(answer, Exception):
                    raise answer
                return answer
        raise AssertionError(f"the test gave no answer for {method} {path}")

    def call(self, method, path, body=None, token=github_api.TOKEN):
        self.asked.append((method, path, body, token))
        return self._answer(method, path)

    def archive(self, artifact, token=github_api.TOKEN):
        self.asked.append(("ARCHIVE", str(artifact), None, token))
        return self.archives[artifact]

    def graphql(self, query, variables, token=github_api.TOKEN):
        self.queries.append((variables, token))
        if isinstance(self.graphql_answer, Exception):
            raise self.graphql_answer
        return self.graphql_answer

    def search_issues(self, query, most, token=github_api.TOKEN):
        self.asked.append(("SEARCH", query, None, token))
        return self.found.get(query, [])

    def writes(self) -> list[tuple[str, str, dict | None, str]]:
        return [asked for asked in self.asked if asked[0] in ("POST", "PATCH", "PUT", "DELETE")]


class Sequence(list):
    """Answers given one per request."""


@contextlib.contextmanager
def running(github: GitHub, environment: dict | None = None):
    """A step run in the repository's root, against the fake, with the runner's environment and an output file."""
    with tempfile.TemporaryDirectory() as directory:
        output = Path(directory) / "output"
        output.touch()
        values = {
            "GITHUB_OUTPUT": str(output),
            "GITHUB_REPOSITORY": REPOSITORY,
            "GITHUB_REF_NAME": "main",
            "GH_TOKEN": "t",
            "APP_TOKEN": "a",
            **(environment or {}),
        }
        cwd = os.getcwd()
        with mock.patch.dict(os.environ, values, clear=True), \
                mock.patch.object(github_api, "call", github.call), \
                mock.patch.object(github_api, "archive", github.archive), \
                mock.patch.object(github_api, "graphql", github.graphql), \
                mock.patch.object(github_api, "search_issues", github.search_issues):
            os.chdir(ROOT)
            try:
                yield output
            finally:
                os.chdir(cwd)


def outputs(output: Path) -> dict[str, str]:
    return dict(line.split("=", 1) for line in output.read_text().splitlines())
