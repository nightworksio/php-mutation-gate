<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Core\Report\TestsText;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('prints the useless tests as the console shows them, a line to each, and asks for a full matrix before naming any removable', function (): void {
    expect(TestsText::of(Killings::verdict(MatrixKind::FirstKiller)))->toBe(<<<'TEXT'
        Tests mutation cannot see
        This judges mutation kills only, and never fails anything. One test judged no mutant with a known result, so it is not assessed.

        Kills nothing it covers (0)
          None.

        Never the first to kill (2)
          A suspicion, not a finding: run `mutation-gate run --kill-matrix=full` to settle it.
          tests/BTest.php::it does B  judged 3  rows: #0 never-first, #1 kills-nothing
          tests/CTest.php::it does C  judged 2

        Removable
          Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.

        TEXT);
});

it('prints the tests that can go from a full matrix, each with its time and the kept test that makes each kill', function (): void {
    expect(TestsText::of(Killings::verdict(MatrixKind::Full)))->toEndWith(<<<'TEXT'
        Removable (2)
          tests/BTest.php::it does B  1.00s  kills 219893cfc02e by tests/ATest.php::it does A
          tests/CTest.php::it does C  0.20s  kills nothing
          The kept set is a small one, not the smallest.

        TEXT)
        ->and(TestsText::of(Killings::verdict(MatrixKind::Full)))->toContain("Kills nothing it covers (1)\n  tests/CTest.php::it does C  judged 2\n");
});

it('says why it names no useless test where the verdict holds no coverage', function (): void {
    expect(TestsText::of(Verdicts::failing()))->toBe(<<<'TEXT'
        Tests mutation cannot see
        This judges mutation kills only, and never fails anything.

        The verdict holds no coverage map, so no test can be judged by what it kills.

        Removable
          Needs a full kill matrix: run `mutation-gate run --kill-matrix=full`.

        TEXT)
        ->and(TestsReport::text(Verdicts::failing()))->toBe(TestsText::of(Verdicts::failing()));
});
