"""The reusable workflow is what its template gives, every download retried once (ADR-0072)."""

from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("generate", ROOT / "scripts/workflows/generate.py")
generate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(generate)

PIN = "actions/download-artifact@9000827ccba6bdab643e8b6fd33ac0654aef8333 # v8.0.2"


class Generated(unittest.TestCase):
    def test_the_committed_workflow_is_what_the_template_gives(self):
        self.assertEqual(0, generate.main(["--check"]))

    def test_the_workflow_says_it_is_generated_and_how(self):
        first = (ROOT / generate.WORKFLOW).read_text(encoding="utf-8").split("\n")[0]

        self.assertIn(generate.TEMPLATE.as_posix(), first)
        self.assertIn(generate.RECIPE, first)

    def test_a_download_becomes_a_timed_step_and_a_retry_of_the_same_artifact_and_path(self):
        template = "\n".join([
            "    steps:",
            f"      - uses: {PIN}",
            "        if: inputs.coverage != ''",
            "        with:",
            "          name: ${{ inputs.coverage }}",
            "          path: ${{ env.HANDED }}",
            "      - name: Plan",
        ])

        self.assertEqual(
            generate.header() + [
                "    steps:",
                f"      - uses: {PIN}",
                "        id: download-map",
                "        if: inputs.coverage != ''",
                "        timeout-minutes: ${{ inputs.download-timeout }}",
                "        continue-on-error: true",
                "        with:",
                "          name: ${{ inputs.coverage }}",
                "          path: ${{ env.HANDED }}",
                "      - name: Retry downloading the coverage map",
                "        if: steps.download-map.outcome == 'failure'",
                f"        uses: {PIN}",
                "        timeout-minutes: ${{ inputs.download-timeout }}",
                "        with:",
                "          name: ${{ inputs.coverage }}",
                "          path: ${{ env.HANDED }}",
                "      - name: Plan",
            ],
            generate.generated(template).split("\n"),
        )

    def test_a_download_that_may_find_nothing_keeps_continuing_on_its_retry(self):
        template = "\n".join([
            f"      - uses: {PIN}",
            "        continue-on-error: true",
            "        with:",
            "          pattern: mutation-gate-result-${{ needs.plan.outputs.attempt }}-*",
        ])
        retry = generate.generated(template).split("      - name: Retry downloading the shards' results\n")[1]

        self.assertIn("        continue-on-error: true\n", retry)

    def test_a_delivery_is_named_by_the_job_that_left_it(self):
        named = [
            generate.what(f"mutation-gate-delivery-{kind}${{{{ needs.plan.outputs.attempt }}}}")[1]
            for kind in ("plan-", "survivors-", "")
        ]

        self.assertEqual(["the plan's delivery", "the survivors' delivery", "the verdict's delivery"], named)

    def test_a_download_of_an_artifact_no_retry_is_named_for_stops_the_generator(self):
        template = f"      - uses: {PIN}\n        with:\n          name: something-else\n"

        with self.assertRaises(generate.Unnamed):
            generate.generated(template)


if __name__ == "__main__":
    unittest.main()
