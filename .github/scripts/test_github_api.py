"""The API client's validation: python3 -m unittest discover .github/scripts"""
import io
import os
import unittest
import urllib.error
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


if __name__ == "__main__":
    unittest.main()
