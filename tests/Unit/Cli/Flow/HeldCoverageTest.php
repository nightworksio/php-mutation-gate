<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\HeldCoverage;
use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
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
    $checks = new HeldCoverage($adapters, HeldCoverageMode::Map)->checked($shard, $suite);

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
