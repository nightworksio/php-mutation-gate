<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$sources = static fn(): array => ['src/Money.php' => Contents::of(Verdicts::MONEY)];

it('writes a report the mutation-testing-report-schema accepts', function (string $verdict) use ($sources): void {
    expect(Schema::errors(Stryker::json(Verdicts::named($verdict), $sources()), Schema::at('tests/Fixtures/mutation-testing-report-schema.json')))->toBe([]);
})->with(['failing', 'passing', 'empty']);

it('writes each mutated file with its source and its mutants', function () use ($sources): void {
    $report = Stryker::json(Verdicts::failing(), $sources());

    expect(Decoded::at($report))->toMatchArray([
        'schemaVersion' => '2',
        'thresholds' => ['high' => 80, 'low' => 60],
        'projectRoot' => '.',
        'framework' => ['name' => 'mutation-gate'],
    ])
        ->and(Decoded::at($report, 'files'))->toHaveKeys(['src/Money.php', 'src/Order.php', 'src/Log.php'])
        ->and(Decoded::at($report, 'files'))->toHaveCount(3)
        ->and(Decoded::at($report, 'files', 'src/Money.php', 'language'))->toBe('php')
        ->and(Decoded::at($report, 'files', 'src/Money.php', 'source'))->toBe(Verdicts::MONEY)
        ->and(Decoded::at($report, 'files', 'src/Order.php', 'source'))->toBe('')
        ->and(Decoded::at($report, 'files', 'src/Money.php', 'mutants'))->toHaveCount(3);
});

it('writes a mutant at its columns, with its judgement, and its tests, hint and command described', function () use ($sources): void {
    $survivor = Verdicts::survivor();
    $id = $survivor->mutant()->id()->value();
    $mutant = Decoded::at(Stryker::json(Verdicts::failing(), $sources()), 'files', 'src/Money.php', 'mutants', 0);

    expect($mutant)->toBe([
        'id' => $id,
        'mutatorName' => 'LessToLessOrEqual',
        'replacement' => 'if ($amount <= $limit) {',
        'location' => ['start' => ['line' => 7, 'column' => 21], 'end' => ['line' => 7, 'column' => 22]],
        'status' => 'Survived',
        'statusReason' => 'survived',
        'description' => implode("\n", [
            'Judged by: MoneyTest::fits, MoneyTest::refuses, PriceTest::adds, CartTest::totals',
            $survivor->hint()->text(),
            sprintf('Reproduce: vendor/bin/mutation-gate reproduce %s', $id),
            sprintf('Explain: vendor/bin/mutation-gate explain %s', $id),
        ]),
    ]);
});

it('gives each judgement the viewer status the gate\'s score treats the same way', function (): void {
    $report = Stryker::json(Verdicts::failing(), []);
    $of = static fn(string $file, string $key): array => Decoded::column($report, $key, 'files', $file, 'mutants');

    expect($of('src/Money.php', 'status'))->toBe(['Survived', 'Killed', 'NoCoverage'])
        ->and($of('src/Money.php', 'statusReason'))->toBe(['survived', 'killed', 'uncovered'])
        ->and($of('src/Order.php', 'status'))->toBe(['Survived', 'Survived', 'Survived', 'Timeout'])
        ->and($of('src/Order.php', 'statusReason'))->toBe(['flaky', 'unjudged: The run\'s budget ran out before it.', 'too slow to judge', 'killed by timeout'])
        ->and($of('src/Log.php', 'status'))->toBe(['Ignored', 'Ignored', 'Killed', 'Ignored'])
        ->and($of('src/Log.php', 'statusReason'))->toBe([
            'ignored: Logging is asserted in the integration suite',
            'ignored by a native marker',
            'errored',
            'equivalent, proven',
        ]);
});

it('ignores an uncovered mutant the score leaves out', function (): void {
    $tree = TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        Judged::mutants(MutantJudgement::Uncovered),
        Uncovered::Exclude,
    );
    expect(Decoded::at(Stryker::json(Verdict::of(TreeVerdicts::of($tree)), []), 'files', 'src/Money.php', 'mutants', 0, 'status'))->toBe('Ignored');
});

it('writes a report with no mutant as an object of no files', function (): void {
    expect(Stryker::json(Verdicts::empty(), []))->toContain('"files": {}');
});
