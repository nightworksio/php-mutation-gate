<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\HeldCoverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;

/** The checks of a shard holding src/Money.php by the group `money`, whose tests the runner lists in the suite `Unit` as these. */
function checkedFromTheMap(TestIds $listed): HeldChecks
{
    $suite = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('MoneyTest::rounds'));
    $unit = Unit::held(Path::of('src/Money.php'), Group::named('money'));
    $shard = Shard::of(ShardId::of(1), Package::at(Path::root()), Units::none()->with($unit), Seconds::of(1.0), 'src');
    $runner = RunnerFake::ofTheFixture()->listingIn(
        Suites::named(SuiteName::of('Unit')),
        TestListing::none()->grouping(Group::named('money'), $listed),
    );
    $adapters = Flows::adapters(Flows::project(), [], $runner, Narrowing::none()->toSuite(SuiteName::of('Unit')));
    $checks = new HeldCoverage($adapters, HeldCoverageMode::Map)->checked($shard, $suite, Briefing::standard());

    return $checks instanceof HeldChecks ? $checks : throw new UnexpectedValueException($checks->why());
}

it('reads a group from the runner\'s listing of the suites the group runs, and its lines from the map', function (): void {
    $checks = checkedFromTheMap(TestIds::of(TestId::of('MoneyTest::adds')));

    expect($checks->misses()->count())->toBe(0)
        ->and($checks->covered()->testsOf(Path::of('src/Money.php')))->toEqual(TestIds::of(TestId::of('MoneyTest::adds')));
});

it('finds the lines a listed group misses in the map', function (): void {
    $checks = checkedFromTheMap(TestIds::of(TestId::of('MoneyTest::rounds')));

    expect($checks->misses()->misses(Path::of('src/Money.php')))->toBeTrue()
        ->and($checks->covered()->count())->toBe(0);
});

/** A shard of this id holding src/Held.php by its group, beside src/Money.php, which the whole suite judges. */
function heldShard(int $id): Shard
{
    $units = Units::none()
        ->with(Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')))
        ->with(Unit::file(Path::of('src/Money.php')));

    return Shard::of(ShardId::of($id), Package::at(Path::root()), $units, Seconds::of(1.0), 'src');
}

it('measures each held unit of every shard once as the plan is made, for every shard to take', function (): void {
    $suite = Flows::map();
    $held = new HeldCoverage(Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture()))
        ->briefed(Briefing::standard(), Shards::of(heldShard(1), heldShard(2)), $suite)
        ->held();

    expect($held)->toBeInstanceOf(MeasuredHolds::class)
        ->and($held instanceof MeasuredHolds ? array_keys($held->all()) : [])->toBe(['src/Held.php'])
        ->and($held instanceof MeasuredHolds ? $held->all()['src/Held.php'] : null)
        ->toEqual(TestIds::of(TestId::of('HeldTest::doubles')));
});

it('leaves each shard to measure its own where told to, or where the plan cut no shards', function (HeldCoverageMode $mode, Shards|CannotJudge $shards): void {
    $suite = Flows::map();
    $briefed = new HeldCoverage(Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture()), $mode)
        ->briefed(Briefing::standard(), $shards, $suite);

    expect($briefed->held())->toEqual(NotGiven::value());
})->with([
    'in each shard' => [HeldCoverageMode::MeasureInEachShard, fn(): Shards => Shards::of(heldShard(1))],
    'from the map' => [HeldCoverageMode::Map, fn(): Shards => Shards::of(heldShard(1))],
    'no shards cut' => [HeldCoverageMode::Measure, fn(): CannotJudge => CannotJudge::because('no cut')],
]);

it('takes what the plan measured and runs nothing, unless the plan was made without a fresh map', function (): void {
    $suite = Flows::map();
    $measured = MeasuredHolds::of(['src/Held.php' => TestIds::of(TestId::of('PlannedTest::holds'))]);
    $held = new HeldCoverage(Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture()));
    $taken = $held->checked(heldShard(1), $suite, Briefing::standard()->measuring($measured));
    $early = $held->checked(heldShard(1), $suite, Briefing::standard()->measuring($measured)->plannedEarly());

    expect($taken instanceof HeldChecks ? $taken->covered()->testsOf(Path::of('src/Held.php')) : $taken)
        ->toEqual(TestIds::of(TestId::of('PlannedTest::holds')))
        ->and($early instanceof HeldChecks ? $early->covered()->testsOf(Path::of('src/Held.php')) : $early)
        ->toEqual(TestIds::of(TestId::of('HeldTest::doubles'), TestId::of('MoneyTest::adds')));
});

it('measures a shard\'s own held units where the plan measured none of them', function (): void {
    $suite = Flows::map();
    $measured = MeasuredHolds::of(['src/Other.php' => TestIds::of(TestId::of('PlannedTest::holds'))]);
    $checks = new HeldCoverage(Flows::adapters(Flows::project(), [], RunnerFake::ofTheFixture()))
        ->checked(heldShard(1), $suite, Briefing::standard()->measuring($measured));

    expect($checks instanceof HeldChecks ? $checks->covered()->testsOf(Path::of('src/Held.php')) : $checks)
        ->toEqual(TestIds::of(TestId::of('HeldTest::doubles')));
});
