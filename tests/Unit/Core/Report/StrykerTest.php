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
})->with(['failing', 'passing', 'empty', 'with a matrix', 'proved']);

it('fills the viewer\'s test view: the tests by file, and those that cover, killed and ran for each mutant', function () use ($sources): void {
    $report = Stryker::json(Verdicts::named('with a matrix'), $sources());
    $mutant = static fn(int $at): mixed => Decoded::at($report, 'files', 'src/Money.php', 'mutants', $at);

    expect(Decoded::at($report, 'testFiles'))->toBe([
        'tests/Unit/MoneyTest.php' => ['tests' => [
            ['id' => 'MoneyTest::fits', 'name' => 'it fits'],
            ['id' => 'MoneyTest::refuses', 'name' => 'it fits with data set "over"'],
        ]],
        'PriceTest' => ['tests' => [['id' => 'PriceTest::adds', 'name' => 'PriceTest::adds']]],
    ])
        ->and($mutant(0))->toMatchArray([
            'coveredBy' => ['MoneyTest::fits', 'MoneyTest::refuses', 'PriceTest::adds'],
            'killedBy' => [],
            'testsCompleted' => 3,
        ])
        ->and($mutant(1))->toMatchArray(['coveredBy' => ['MoneyTest::fits', 'PriceTest::adds'], 'killedBy' => ['MoneyTest::fits'], 'testsCompleted' => 1]);
});

it('writes no test file where no mutant names a test', function () use ($sources): void {
    expect(Stryker::json(Verdicts::passing(), $sources()))->toContain('"testFiles": {}');
});

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
        'coveredBy' => [],
        'killedBy' => [],
        'testsCompleted' => 0,
    ]);
});

it('gives each judgement the viewer status the gate\'s score treats the same way', function (): void {
    $report = Stryker::json(Verdicts::failing(), []);
    $of = static fn(string $file, string $key): array => Decoded::column($report, $key, 'files', $file, 'mutants');

    expect($of('src/Money.php', 'status'))->toBe(['Survived', 'Killed', 'NoCoverage'])
        ->and($of('src/Money.php', 'statusReason'))->toBe(['survived', 'killed', 'uncovered'])
        ->and($of('src/Order.php', 'status'))->toBe(['Survived', 'Survived', 'Survived', 'Timeout'])
        ->and($of('src/Order.php', 'statusReason'))->toBe(['flaky', 'unjudged: The run\'s budget ran out before it.', 'too slow to judge', 'killed by timeout'])
        ->and($of('src/Log.php', 'status'))->toBe(['Ignored', 'Ignored', 'Killed', 'Ignored', 'Killed'])
        ->and($of('src/Log.php', 'statusReason'))->toBe([
            'ignored: Logging is asserted in the integration suite',
            'ignored by a native marker',
            'errored',
            'equivalent, proven',
            'killed by static analysis',
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

it('names the judging tests in a mutant\'s description as the runner named them', function () use ($sources): void {
    $report = Stryker::json(Verdicts::named('with a matrix'), $sources());

    expect(Decoded::at($report, 'files', 'src/Money.php', 'mutants', 0, 'description'))
        ->toStartWith('Judged by: tests/Unit/MoneyTest.php::it fits, tests/Unit/MoneyTest.php::it fits with data set "over", PriceTest::adds');
});

it('writes a kill a ledger proved across its whole line, with no replacement, which the ledger does not keep', function () use ($sources): void {
    $kill = Verdicts::provedKill()->mutant();
    $line = explode("\n", Verdicts::MONEY)[8];
    $mutant = Decoded::at(Stryker::json(Verdicts::named('proved'), $sources()), 'files', 'src/Money.php', 'mutants', 1);

    expect($mutant)->toMatchArray([
        'id' => $kill->id()->value(),
        'mutatorName' => 'TrueValue',
        'location' => ['start' => ['line' => 9, 'column' => 1], 'end' => ['line' => 9, 'column' => mb_strlen($line) + 1]],
        'status' => 'Killed',
        'killedBy' => ['MoneyTest::fits'],
    ])
        ->and($mutant)->not->toHaveKey('replacement');
});
