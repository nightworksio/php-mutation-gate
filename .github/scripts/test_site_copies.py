"""The deciding half of site_copies.py: python3 -m unittest discover .github/scripts"""
import unittest

import site_copies


class Copies(unittest.TestCase):
    def test_holds_main_at_the_root_and_at_next_while_no_version_is_tagged(self):
        self.assertEqual(site_copies.copies([]), [
            {"path": "", "ref": "main", "strict": True},
            {"path": "/next", "ref": "main", "strict": True},
        ])

    def test_gives_each_minor_its_newest_tag_and_the_root_the_newest_released_minor(self):
        tags = ["v0.1.0", "v0.1.2", "v0.1.10", "v0.2.0-rc.1", "v0.2.0-rc.2", "notes", "v1.0"]
        self.assertEqual(site_copies.copies(tags), [
            {"path": "", "ref": "v0.1.10", "strict": False},
            {"path": "/next", "ref": "main", "strict": True},
            {"path": "/0.2", "ref": "v0.2.0-rc.2", "strict": False},
            {"path": "/0.1", "ref": "v0.1.10", "strict": False},
        ])

    def test_takes_a_release_after_its_pre_releases(self):
        self.assertEqual(site_copies.copies(["v0.2.0-rc.1", "v0.2.0"])[2], {"path": "/0.2", "ref": "v0.2.0", "strict": False})

    def test_compares_numeric_pre_release_identifiers_as_numbers(self):
        self.assertEqual(site_copies.copies(["v0.2.0-rc.2", "v0.2.0-rc.10"])[2]["ref"], "v0.2.0-rc.10")

    def test_keeps_main_at_the_root_while_every_tag_is_a_pre_release(self):
        self.assertEqual(site_copies.copies(["v0.1.0-beta.1"])[0], {"path": "", "ref": "main", "strict": True})

    def test_orders_minors_by_number_not_by_text(self):
        paths = [copy["path"] for copy in site_copies.copies(["v0.9.0", "v0.10.0", "v1.0.0"])]
        self.assertEqual(paths, ["", "/next", "/1.0", "/0.10", "/0.9"])


class Versions(unittest.TestCase):
    def test_lists_next_then_each_minor_and_names_the_one_at_the_root(self):
        self.assertEqual(site_copies.versions(["v0.1.0", "v0.2.0-rc.1"]), {
            "versions": [{"name": "next", "path": "/next/"}, {"name": "0.2", "path": "/0.2/"}, {"name": "0.1", "path": "/0.1/"}],
            "root": "0.1",
        })

    def test_names_next_at_the_root_while_nothing_is_released(self):
        self.assertEqual(site_copies.versions([]), {"versions": [{"name": "next", "path": "/next/"}], "root": "next"})


class Usage(unittest.TestCase):
    def test_says_how_it_is_used_where_it_is_given_no_command(self):
        self.assertEqual(site_copies.main([]), 2)


if __name__ == "__main__":
    unittest.main()
