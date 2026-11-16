import contextlib
import io
import json
import unittest

import servers


class Servers(unittest.TestCase):
    def test_writes_the_services_as_one_line_a_job_reads_back_as_they_are(self):
        document = {"postgres": {"image": "postgres:17@sha256:0d93", "ports": ["54321:5432"]}, "mysql": {"ports": ["33061:3306"]}}

        written = servers.services(document)

        self.assertNotIn("\n", written)
        self.assertNotIn(" ", written)
        self.assertEqual(document, json.loads(written))

    def test_names_each_server_by_its_id_on_the_host_port_its_first_mapping_publishes(self):
        document = {"postgres": {"ports": ["54321:5432", "54322:5433"]}, "mariadb": {"ports": ["33062:3306"]}}

        self.assertEqual(
            ["MUTATION_GATE_TEST_MARIADB=127.0.0.1:33062", "MUTATION_GATE_TEST_POSTGRES=127.0.0.1:54321"],
            servers.addresses(document),
        )

    def test_the_file_names_the_servers_the_suite_reads_each_pinned_by_digest_and_waited_for(self):
        document = json.loads(servers.SERVICES.read_text(encoding="utf-8"))

        self.assertEqual(["mariadb", "mysql", "postgres"], sorted(document))
        for name, service in document.items():
            with self.subTest(server=name):
                self.assertRegex(service["image"], r"^[a-z]+:[0-9.]+@sha256:[0-9a-f]{64}$")
                self.assertIn("--health-cmd=", service["options"])

    def test_prints_what_it_is_asked_for_and_refuses_anything_else(self):
        printed = io.StringIO()
        with contextlib.redirect_stdout(printed):
            self.assertEqual(0, servers.main(["services"]))
            self.assertEqual(0, servers.main(["addresses"]))
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(2, servers.main(["other"]))

        lines = printed.getvalue().splitlines()
        self.assertTrue(lines[0].startswith("services={"))
        self.assertIn("MUTATION_GATE_TEST_MYSQL=127.0.0.1:33061", lines)


if __name__ == "__main__":
    unittest.main()
