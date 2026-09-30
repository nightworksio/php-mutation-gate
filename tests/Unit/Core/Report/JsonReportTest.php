<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
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
use NightWorksIO\MutationGate\Core\Score\Percentage;
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
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('writes everything in the verdict, and what the committed schema describes', function (string $verdict): void {
    $json = JsonReport::encode(Verdicts::named($verdict));

    expect(Schema::errors($json, Schema::at('resources/report.schema.json')))->toBe([]);
})->with(['failing', 'passing', 'empty', 'with a matrix', 'cannot judge', 'proved', 'clustered']);

it('says the run cannot judge, and why', function (): void {
    $report = JsonReport::encode(Verdicts::named('cannot judge'));

    expect(Decoded::at($report, 'judgement'))->toBe('cannot-judge')
        ->and(Decoded::at($report, 'cannotJudge'))->toBe([Verdicts::UNJUDGED]);
});

it('lists the tests once, and points each mutant at those that cover and killed it', function (): void {
    $report = JsonReport::encode(Verdicts::named('with a matrix'));

    expect(Decoded::at($report, 'matrix'))->toBe('first-killer')
        ->and(Decoded::at($report, 'tests'))->toBe([
            ['id' => 'MoneyTest::fits', 'name' => 'tests/Unit/MoneyTest.php::it fits', 'file' => 'tests/Unit/MoneyTest.php', 'seconds' => 0.25],
            ['id' => 'MoneyTest::refuses', 'name' => 'tests/Unit/MoneyTest.php::it fits with data set "over"', 'file' => 'tests/Unit/MoneyTest.php', 'row' => '"over"'],
            ['id' => 'PriceTest::adds', 'name' => 'PriceTest::adds'],
        ])
        ->and(Decoded::at($report, 'mutants', 0))->toMatchArray(['coveredBy' => [0, 1, 2], 'killedBy' => []])
        ->and(Decoded::at($report, 'mutants', 1))->toMatchArray(['coveredBy' => [0, 2], 'killedBy' => [0]])
        ->and(Decoded::at($report, 'mutants', 2))->toMatchArray(['coveredBy' => [], 'killedBy' => []]);
});

it('knows the killers each record names where the run gave no matrix', function (): void {
    $report = JsonReport::encode(Verdicts::failing());

    expect(Decoded::at($report, 'matrix'))->toBe('first-killer')
        ->and(Decoded::at($report, 'tests'))->toBe([['id' => 'MoneyTest::fits', 'name' => 'MoneyTest::fits']])
        ->and(Decoded::at($report, 'mutants', 1))->toMatchArray(['coveredBy' => [0], 'killedBy' => [0]]);
});

it('writes the verdict, the project and each tree', function (): void {
    $report = JsonReport::encode(Verdicts::failing());

    expect(Decoded::at($report))->toMatchArray([
        'format' => 1,
        'judgement' => 'failed',
        'cutShort' => false,
        'uncovered' => 'count',
        'score' => 44.44,
        'reach' => ['src/Money.php changed, so it is reached.'],
        'warnings' => ['src/Kernel.php is run by 412 of 430 tests and nothing holds it.'],
        'failures' => ['The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.'],
        'cannotJudge' => [],
    ])
        ->and(Decoded::at($report, 'counts'))->toBe([
            'killed' => 1, 'killed-by-static-analysis' => 1, 'errored' => 1, 'killed-by-timeout' => 1,
            'survived' => 1, 'uncovered' => 1, 'unjudged' => 1, 'flaky' => 1, 'too-slow-to-judge' => 1, 'ignored' => 1, 'ignored-by-marker' => 1,
            'equivalent' => 1,
        ])
        ->and(Decoded::at($report, 'trees', 0))->toMatchArray([
            'path' => 'src',
            'package' => '.',
            'declared' => 80.0,
            'baseline' => 75.5,
            'floor' => 80.0,
            'score' => 44.44,
            'base' => 46.94,
            'judgement' => 'failed',
            'units' => [
                ['path' => 'src/Money.php', 'origin' => 'run'],
                ['path' => 'src/Order.php', 'origin' => 'proved'],
                ['path' => 'src/Log.php', 'group' => 'holds:src/Log.php', 'origin' => 'carried'],
            ],
        ])
        ->and(Decoded::at($report, 'trees', 0))->not->toHaveKey('raised')
        ->and(Decoded::at($report, 'trees', 0, 'mutants'))->toHaveCount(12)
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
                'killed' => 0, 'killed-by-static-analysis' => 0, 'errored' => 0, 'killed-by-timeout' => 0,
                'survived' => 1, 'uncovered' => 0, 'unjudged' => 0, 'flaky' => 0, 'too-slow-to-judge' => 0, 'ignored' => 0, 'ignored-by-marker' => 0,
                'equivalent' => 0,
            ],
            'mutants' => [Verdicts::survivor()->mutant()->id()->value()],
        ]]);
});

it('writes the rejection of a mutant a static analyser killed, and none of any other', function (): void {
    $report = JsonReport::encode(Verdicts::failing());

    expect(Decoded::at($report, 'mutants', 11))->toMatchArray([
        'id' => Verdicts::rejected()->id()->value(),
        'status' => 'killed-by-static-analysis',
        'judgement' => 'killed-by-static-analysis',
        'rejection' => [
            'analyser' => 'phpstan',
            'code' => 'return.type',
            'message' => 'Method Log::id() should return string but returns int.',
        ],
    ])
        ->and(Decoded::at($report, 'mutants', 1))->not->toHaveKey('rejection');
});

it('writes every mutant once, with its judgement, tests, hint, and reproduce and explain commands', function (): void {
    $report = JsonReport::encode(Verdicts::failing());
    $survivor = Verdicts::survivor();
    $id = $survivor->mutant()->id()->value();

    expect(Decoded::at($report, 'mutants'))->toHaveCount(12)
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
            'coveredBy' => [],
            'killedBy' => [],
            'hint' => $survivor->hint()->text(),
            'reproduce' => sprintf('vendor/bin/mutation-gate reproduce %s', $id),
            'explain' => sprintf('vendor/bin/mutation-gate explain %s', $id),
        ])
        ->and(Decoded::at($report, 'mutants', 4))->toMatchArray(['judgement' => 'unjudged', 'reason' => 'The run\'s budget ran out before it.'])
        ->and(Decoded::at($report, 'mutants', 5))->toMatchArray(['judgement' => 'too-slow-to-judge', 'limit' => 5.0])
        ->and(Decoded::at($report, 'mutants', 7))->toMatchArray(['judgement' => 'ignored', 'reason' => 'Logging is asserted in the integration suite'])
        ->and(Decoded::at($report, 'mutants', 10))->toMatchArray(['status' => 'survived', 'judgement' => 'equivalent']);
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

it('writes the run\'s timings, what it cost and what it saved, each in the shape the schema holds it to', function (): void {
    $report = JsonReport::encode(Verdicts::named('accounted'));

    expect(Schema::errors($report, Schema::at('resources/report.schema.json')))->toBe([])
        ->and(Decoded::at($report, 'run'))->toBe([
            'id' => 'github:12345/1',
            'traceId' => '9b2d6cafc59d87d7a027463ce78b2d84',
            'phases' => [
                'plan' => ['start' => '2026-09-30T11:50:00Z', 'seconds' => 40.0],
                'verdict' => ['start' => '2026-09-30T11:55:10Z', 'seconds' => 30.0],
            ],
            'shards' => [
                ['shard' => 1, 'start' => '2026-09-30T11:51:00Z', 'openingRunSeconds' => 20.0, 'mutateSeconds' => 200.0],
                ['shard' => 2, 'start' => '2026-09-30T11:51:05Z', 'openingRunSeconds' => 25.0, 'mutateSeconds' => 180.0],
            ],
            'units' => ['run' => 1, 'proved' => 1, 'carried' => 1],
            'wallSeconds' => 360.0,
            'runnerSeconds' => 840.0,
            'measured' => true,
        ])
        ->and(Decoded::at($report, 'cost'))->toBe([
            'planned' => ['wallSeconds' => 420.0, 'runnerSeconds' => 900.0],
            'measured' => ['wallSeconds' => 360.0, 'runnerSeconds' => 840.0],
            'spared' => ['seconds' => 2460.0],
            'setupEstimated' => false,
        ])
        ->and(Decoded::at($report, 'savings'))->toBe([
            'fullRun' => ['seconds' => 6060.0, 'measuredPercent' => 94],
            'saved' => ['reachSeconds' => 4800.0, 'proofsSeconds' => 420.0],
            'sharding' => ['waitSavedSeconds' => 2280.0, 'setupSeconds' => 180.0],
        ]);
});

it('prices each cost where the team gives a rate, and says a timed run with no history has none', function (): void {
    $account = Verdicts::account();
    $cost = $account->cost();
    $priced = $cost instanceof Cost ? $account->withCost($cost->pricedAt(Rate::perMinute(0.5, 'EUR'))) : $account;
    $timings = $account->timings();
    $unsaved = $timings instanceof RunTimings ? RunAccount::none()->withTimings($timings->withShard(Verdicts::shard())) : $account;
    $report = JsonReport::encode(Verdicts::failing()->withAccount($priced));
    $bare = JsonReport::encode(Verdicts::failing()->withAccount($unsaved));

    expect(Schema::errors($report, Schema::at('resources/report.schema.json')))->toBe([])
        ->and(Decoded::at($report, 'cost'))->toBe([
            'planned' => ['wallSeconds' => 420.0, 'runnerSeconds' => 900.0, 'price' => ['amount' => 7.5, 'currency' => 'EUR']],
            'measured' => ['wallSeconds' => 360.0, 'runnerSeconds' => 840.0, 'price' => ['amount' => 7.0, 'currency' => 'EUR']],
            'spared' => ['seconds' => 2460.0, 'price' => ['amount' => 20.5, 'currency' => 'EUR']],
            'setupEstimated' => false,
            'perRunnerMinute' => ['amount' => 0.5, 'currency' => 'EUR'],
        ])
        ->and(Schema::errors($bare, Schema::at('resources/report.schema.json')))->toBe([])
        ->and(Decoded::at($bare, 'savings'))->toBe(['noHistory' => true])
        ->and(Decoded::at($bare))->not->toHaveKey('cost')
        ->and(Decoded::at(JsonReport::encode(Verdicts::failing())))->not->toHaveKeys(['run', 'cost', 'savings']);
});

it('leaves out the phases no one timed, and the sharding of an unsharded run', function (): void {
    $timings = RunTimings::of('local', RunTime::estimated(Seconds::of(10.0), Seconds::of(70.0)));
    $savings = Savings::of(Seconds::of(100.0), Percentage::of(Floor::of(50)), Seconds::of(10.0), Seconds::of(20.0));
    $report = JsonReport::encode(Verdicts::failing()->withAccount(RunAccount::none()->withTimings($timings)->withSavings($savings)));

    expect($report)->toContain('"phases": {}')
        ->and(Decoded::at($report, 'run', 'measured'))->toBeFalse()
        ->and(Decoded::at($report, 'savings'))->not->toHaveKey('sharding')
        ->and(Schema::errors($report, Schema::at('resources/report.schema.json')))->toBe([]);
});

it('writes a kill a ledger proved with no family or diff, which the ledger does not keep', function (): void {
    $report = JsonReport::encode(Verdicts::named('proved'));
    $kill = Verdicts::provedKill()->mutant();

    expect(Decoded::at($report, 'mutants'))->toHaveCount(2)
        ->and(Decoded::at($report, 'mutants', 0))->toHaveKeys(['family', 'diff'])
        ->and(Decoded::at($report, 'mutants', 1))->toMatchArray([
            'id' => $kill->id()->value(),
            'file' => 'src/Money.php',
            'line' => 9,
            'mutator' => 'TrueValue',
            'status' => 'killed',
            'judgement' => 'killed',
        ])
        ->and(Decoded::at($report, 'mutants', 1))->not->toHaveKeys(['family', 'diff', 'end', 'seconds', 'limit', 'reason'])
        ->and(Decoded::at($report, 'mutants', 1, 'killedBy'))->toBe([0])
        ->and(Decoded::at($report, 'tests', 0, 'id'))->toBe('MoneyTest::fits');
});

it('lists each cluster with its kind, members and representative, and names the cluster each member is in', function (): void {
    $verdict = Clustered::verdict();
    [$expression, $gap] = iterator_to_array($verdict->trees()->clusters(), preserve_keys: false);
    $ids = static fn(Survivors $mutants): array => array_map(static fn(JudgedMutant $judged): string => $judged->mutant()->id()->value(), [...$mutants]);
    $report = JsonReport::encode($verdict);

    expect(Decoded::at($report, 'clusters'))->toBe([
        ['id' => $expression->id()->value(), 'kind' => 'expression', 'members' => $ids($expression->members()), 'representative' => $expression->representative()->mutant()->id()->value()],
        ['id' => $gap->id()->value(), 'kind' => 'gap', 'members' => $ids($gap->members()), 'representative' => $gap->representative()->mutant()->id()->value()],
    ])
        ->and(Decoded::at($report, 'mutants', 0, 'cluster'))->toBe($expression->id()->value())
        ->and(Decoded::at($report, 'mutants', 6, 'cluster'))->toBe($gap->id()->value())
        ->and(Decoded::at($report, 'mutants', 3))->not->toHaveKey('cluster')
        ->and(Decoded::at(JsonReport::encode(Verdicts::failing()), 'clusters'))->toBe([]);
});
