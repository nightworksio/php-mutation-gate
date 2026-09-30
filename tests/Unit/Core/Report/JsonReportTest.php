<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes everything in the verdict, and what the committed schema describes', function (string $verdict): void {
    $json = JsonReport::encode(Verdicts::named($verdict));

    expect(Schema::errors($json, Schema::at('resources/report.schema.json')))->toBe([]);
})->with(['failing', 'passing', 'empty']);

it('writes the verdict, the project and each tree', function (): void {
    $report = JsonReport::encode(Verdicts::failing());

    expect(Decoded::at($report))->toMatchArray([
        'format' => 1,
        'judgement' => 'failed',
        'cutShort' => false,
        'uncovered' => 'count',
        'score' => 37.5,
        'reach' => ['src/Money.php changed, so it is reached.'],
        'warnings' => ['src/Kernel.php is run by 412 of 430 tests and nothing holds it.'],
        'failures' => ['The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.'],
    ])
        ->and(Decoded::at($report, 'counts'))->toBe([
            'killed' => 1, 'errored' => 1, 'killed-by-timeout' => 1, 'survived' => 1, 'uncovered' => 1,
            'unjudged' => 1, 'flaky' => 1, 'too-slow-to-judge' => 1, 'ignored' => 1, 'ignored-by-marker' => 1,
        ])
        ->and(Decoded::at($report, 'trees', 0))->toMatchArray([
            'path' => 'src',
            'package' => '.',
            'declared' => 80.0,
            'baseline' => 75.5,
            'floor' => 80.0,
            'score' => 37.5,
            'base' => 40.0,
            'judgement' => 'failed',
            'units' => [
                ['path' => 'src/Money.php', 'origin' => 'run'],
                ['path' => 'src/Order.php', 'origin' => 'proved'],
                ['path' => 'src/Log.php', 'group' => 'holds:src/Log.php', 'origin' => 'carried'],
            ],
        ])
        ->and(Decoded::at($report, 'trees', 0))->not->toHaveKey('raised')
        ->and(Decoded::at($report, 'trees', 0, 'mutants'))->toHaveCount(10)
        ->and(Decoded::at($report, 'trees', 1))->toMatchArray(['path' => 'app/Legacy', 'exempt' => 'Replaced by the new billing module', 'judgement' => 'exempt'])
        ->and(Decoded::at($report, 'trees', 1))->not->toHaveKeys(['declared', 'baseline', 'floor', 'score', 'base'])
        ->and(Decoded::at($report, 'trees', 2))->toMatchArray(['path' => 'src/Empty', 'floor' => 90.0, 'judgement' => 'nothing-to-mutate'])
        ->and(Decoded::at($report, 'trees', 2))->not->toHaveKey('score')
        ->and(Decoded::at($report, 'newCode'))->toBe([[
            'package' => '.',
            'floor' => 100.0,
            'score' => 0.0,
            'judgement' => 'failed',
            'counts' => [
                'killed' => 0, 'errored' => 0, 'killed-by-timeout' => 0, 'survived' => 1, 'uncovered' => 0,
                'unjudged' => 0, 'flaky' => 0, 'too-slow-to-judge' => 0, 'ignored' => 0, 'ignored-by-marker' => 0,
            ],
            'mutants' => [Verdicts::survivor()->mutant()->id()->value()],
        ]]);
});

it('writes every mutant once, with its judgement, tests, hint and reproduce command', function (): void {
    $report = JsonReport::encode(Verdicts::failing());
    $survivor = Verdicts::survivor();
    $id = $survivor->mutant()->id()->value();

    expect(Decoded::at($report, 'mutants'))->toHaveCount(10)
        ->and(Decoded::at($report, 'mutants', 0))->toBe([
            'id' => $id,
            'file' => 'src/Money.php',
            'line' => 7,
            'end' => 7,
            'mutator' => Verdicts::LESS,
            'family' => 'boundary',
            'diff' => Verdicts::BOUNDARY,
            'status' => 'survived',
            'judgement' => 'survived',
            'changedLine' => true,
            'tests' => ['MoneyTest::fits', 'MoneyTest::refuses', 'PriceTest::adds', 'CartTest::totals'],
            'hint' => $survivor->hint()->text(),
            'reproduce' => sprintf('vendor/bin/mutation-gate reproduce %s', $id),
        ])
        ->and(Decoded::at($report, 'mutants', 4))->toMatchArray(['judgement' => 'unjudged', 'reason' => 'The run\'s budget ran out before it.'])
        ->and(Decoded::at($report, 'mutants', 5))->toMatchArray(['judgement' => 'too-slow-to-judge', 'limit' => 5.0])
        ->and(Decoded::at($report, 'mutants', 7))->toMatchArray(['judgement' => 'ignored', 'reason' => 'Logging is asserted in the integration suite']);
});

it('writes a run cut short, a unit a filter holds, the seconds a mutant ran and the floor a tree raises', function (): void {
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'TrueValue', '', 0),
        '1',
        Location::of(Path::of('src/Money.php'), Line::of(9), Unreported::line()),
        Mutation::of('TrueValue', MutatorFamily::Literal, ''),
        MutantStatus::Killed,
        Seconds::of(0.25),
    );
    $tree = TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::of(JudgedUnit::of(Unit::held(Path::of('src/Money.php'), Filter::matching('MoneyTest')), Origin::Run)),
        JudgedMutants::of(JudgedMutant::of($mutant, MutantJudgement::Killed)),
        Uncovered::Exclude,
    );
    $report = JsonReport::encode(Verdict::of(TreeVerdicts::of($tree))->cutShort());

    expect(Decoded::at($report))->toMatchArray(['cutShort' => true, 'uncovered' => 'exclude'])
        ->and(Decoded::at($report, 'trees', 0))->toMatchArray([
            'raised' => 100.0,
            'units' => [['path' => 'src/Money.php', 'filter' => 'MoneyTest', 'origin' => 'run']],
        ])
        ->and(Decoded::at($report, 'trees', 0))->not->toHaveKey('baseline')
        ->and(Decoded::at($report, 'mutants', 0))->toMatchArray(['seconds' => 0.25])
        ->and(Decoded::at($report, 'mutants', 0))->not->toHaveKeys(['end', 'limit', 'reason']);
});
