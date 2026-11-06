"""The API client's validation: python3 -m unittest discover .github/scripts"""
import io
import os
import unittest
import urllib.error
import urllib.request
from unittest import mock

import github_api

REPOSITORY = {"GITHUB_REPOSITORY": "nightworksio/php-mutation-gate", "GH_TOKEN": "t"}


class Repository(unittest.TestCase):
    def test_only_an_owner_and_a_name_is_a_repository(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True):
            self.assertEqual(github_api.repository(), "nightworksio/php-mutation-gate")
            self.assertEqual(github_api.web_url("actions/runs/9"), "https://github.com/nightworksio/php-mutation-gate/actions/runs/9")

        for name in ("", "a", "a/b/c", "a/b?x", "../b", "a/..", "a/.", "-a/b"):
            with mock.patch.dict(os.environ, {"GITHUB_REPOSITORY": name}, clear=True), self.assertRaises(ValueError):
                github_api.repository()

    def test_a_value_in_a_path_cannot_end_its_segment(self):
        self.assertEqual(github_api.quoted("a/../b?c#d"), "a%2F..%2Fb%3Fc%23d")


class Call(unittest.TestCase):
    def answer(self, text: bytes):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(text)
        return response

    def test_it_asks_this_repository_with_the_token_and_reads_json(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch("urllib.request.urlopen", return_value=self.answer(b'{"id": 7}')) as opened:
            self.assertEqual(github_api.call("POST", "issues/7/comments", {"body": "x"}), {"id": 7})

        request = opened.call_args.args[0]
        self.assertEqual(request.full_url, "https://api.github.com/repos/nightworksio/php-mutation-gate/issues/7/comments")
        self.assertEqual(request.get_method(), "POST")
        self.assertEqual(request.get_header("Authorization"), "Bearer t")
        self.assertEqual(request.data, b'{"body": "x"}')

    def test_no_content_is_none_and_an_error_status_is_raised_with_its_code(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True):
            with mock.patch("urllib.request.urlopen", return_value=self.answer(b"")):
                self.assertIsNone(github_api.call("PUT", "pulls/7/update-branch"))
            failing = urllib.error.HTTPError("u", 422, "no", {}, None)
            with mock.patch("urllib.request.urlopen", side_effect=failing), self.assertRaises(github_api.Failed) as raised:
                github_api.call("PUT", "pulls/7/update-branch")

        self.assertEqual(raised.exception.status, 422)

    def test_an_answer_larger_than_the_bot_reads_is_refused(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch.object(github_api, "MAX_RESPONSE", 4), \
                mock.patch("urllib.request.urlopen", return_value=self.answer(b"[1,2,3]")), \
                self.assertRaises(ValueError):
            github_api.call("GET", "pulls/7")


class Token(unittest.TestCase):
    def answer(self, text: bytes):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(text)
        return response

    def test_a_call_names_the_token_it_uses(self):
        with mock.patch.dict(os.environ, {**REPOSITORY, "APP_TOKEN": "app"}, clear=True), \
                mock.patch("urllib.request.urlopen", return_value=self.answer(b"{}")) as opened:
            github_api.call("PATCH", "issues/comments/1", {"body": "x"}, token="APP_TOKEN")

        self.assertEqual(opened.call_args.args[0].get_header("Authorization"), "Bearer app")


class Listed(unittest.TestCase):
    def test_a_listing_is_read_page_by_page_until_a_page_is_short(self):
        pages = [list(range(100)), [100, 101]]
        with mock.patch.object(github_api, "call", side_effect=pages) as called:
            self.assertEqual(github_api.listed("pulls/7/files"), list(range(102)))

        self.assertEqual(called.call_args_list[0].args, ("GET", "pulls/7/files?per_page=100&page=1"))
        self.assertEqual(called.call_args_list[1].args, ("GET", "pulls/7/files?per_page=100&page=2"))

    def test_a_listing_reads_its_field_and_keeps_its_query(self):
        with mock.patch.object(github_api, "call", return_value={"jobs": [1]}) as called:
            self.assertEqual(github_api.listed("actions/runs/1/jobs?filter=latest", "jobs", token="APP_TOKEN"), [1])

        self.assertEqual(called.call_args.args, ("GET", "actions/runs/1/jobs?filter=latest&per_page=100&page=1"))
        self.assertEqual(called.call_args.kwargs, {"token": "APP_TOKEN"})

    def test_a_listing_stops_at_the_most_pages(self):
        with mock.patch.object(github_api, "MAX_PAGES", 2), \
                mock.patch.object(github_api, "call", return_value=list(range(100))) as called:
            self.assertEqual(len(github_api.listed("issues/1/comments")), 200)

        self.assertEqual(called.call_count, 2)


class Graphql(unittest.TestCase):
    def answer(self, text: bytes):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(text)
        return response

    def test_it_posts_the_query_and_its_variables_and_reads_the_data(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch("urllib.request.urlopen", return_value=self.answer(b'{"data": {"a": 1}}')) as opened:
            self.assertEqual(github_api.graphql("query { a }", {"x": 1}), {"a": 1})

        request = opened.call_args.args[0]
        self.assertEqual(request.full_url, "https://api.github.com/graphql")
        self.assertEqual(request.data, b'{"query": "query { a }", "variables": {"x": 1}}')

    def test_an_answer_with_errors_is_raised(self):
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch("urllib.request.urlopen", return_value=self.answer(b'{"errors": [{"message": "moved"}]}')), \
                self.assertRaises(github_api.Failed) as raised:
            github_api.graphql("mutation { a }", {})

        self.assertEqual(raised.exception.status, 422)


class Search(unittest.TestCase):
    def test_it_asks_the_search_with_the_query_quoted(self):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(b'{"items": [{"number": 3}]}')
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch("urllib.request.urlopen", return_value=response) as opened:
            self.assertEqual(github_api.search_issues('repo:a/b "x"', 10), [{"number": 3}])

        self.assertEqual(opened.call_args.args[0].full_url, "https://api.github.com/search/issues?q=repo%3Aa%2Fb%20%22x%22&per_page=10")

    def test_no_items_is_none_found(self):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(b'{}')
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), mock.patch("urllib.request.urlopen", return_value=response):
            self.assertEqual(github_api.search_issues("x", 1), [])


class Archive(unittest.TestCase):
    def test_it_reads_the_archive_through_an_opener_that_drops_the_token_on_redirect(self):
        response = mock.MagicMock()
        response.__enter__.return_value = io.BytesIO(b"PK")
        opener = mock.MagicMock()
        opener.open.return_value = response
        with mock.patch.dict(os.environ, REPOSITORY, clear=True), \
                mock.patch("urllib.request.build_opener", return_value=opener) as built:
            self.assertEqual(github_api.archive(42), b"PK")

        self.assertEqual(built.call_args.args, (github_api._Unauthorised,))
        request = opener.open.call_args.args[0]
        self.assertEqual(request.full_url, "https://api.github.com/repos/nightworksio/php-mutation-gate/actions/artifacts/42/zip")
        self.assertEqual(request.get_header("Authorization"), "Bearer t")

    def test_a_redirect_leaves_the_token_behind(self):
        request = urllib.request.Request("https://api.github.com/x", headers={"Authorization": "Bearer t", "Accept": "a"})
        handler = github_api._Unauthorised()
        redirected = handler.redirect_request(request, None, 302, "Found", {}, "https://storage.example/blob")

        self.assertEqual(redirected.full_url, "https://storage.example/blob")
        self.assertIsNone(redirected.get_header("Authorization"))
        self.assertEqual(redirected.get_header("Accept"), "a")

    def test_a_redirect_the_handler_refuses_stays_refused(self):
        request = urllib.request.Request("https://api.github.com/x", method="POST", data=b"x")
        handler = github_api._Unauthorised()

        with self.assertRaises(urllib.error.HTTPError):
            handler.redirect_request(request, None, 307, "Temporary", {}, "https://storage.example/blob")


class Comment(unittest.TestCase):
    def test_a_comment_is_posted_on_the_issue(self):
        with mock.patch.object(github_api, "call") as called:
            github_api.comment(7, "hello")

        self.assertEqual(called.call_args.args, ("POST", "issues/7/comments", {"body": "hello"}))


if __name__ == "__main__":
    unittest.main()
