<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\FirstRunShape;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** Two mutants on line 3, which tests a and b cover; one on line 7, which a covers; one on line 9, which none covers. */
function measuredMoney(): FirstRun
{
    $money = Path::of('src/Money.php');
    $a = TestId::of('MoneyTest::a');
    $b = TestId::of('MoneyTest::b');
    $map = CoverageMap::of()
        ->covered($money, Line::of(3), $a)
        ->covered($money, Line::of(3), $b)
        ->covered($money, Line::of(7), $a)
        ->timed($a, Seconds::of(0.5))
        ->timed($b, Seconds::of(1.0));
    $sites = MutantSites::inFile($money, Line::of(3), Line::of(3), Line::of(7), Line::of(9))
        ->and(MutantSites::inFile(Path::of('src/Empty.php')));

    return FirstRun::of($map, $sites, Seconds::of(2.0), ProcessCount::of(2), FirstRunShape::of(overhead: 1.0));
}

it('costs each covered mutant a start-up and its covering tests, spread over the processes', function (): void {
    // (2 × (2 + 0.5 + 1.0) + (2 + 0.5) + 0) / 2
    expect(measuredMoney()->seconds(Unit::file(Path::of('src/Money.php'))))->toEqual(Seconds::of(4.75));
});

it('costs a held directory as every file of it the engine counted', function (): void {
    $held = Unit::held(Path::of('src'), Group::named('holds:src'));

    expect(measuredMoney()->seconds($held))->toEqual(Seconds::of(4.75))
        ->and(measuredMoney()->seconds(Unit::file(Path::of('src/Empty.php'))))->toEqual(Seconds::of(0.0));
});

it('leaves unmeasured a unit none of whose files the engine counted', function (): void {
    expect(measuredMoney()->seconds(Unit::file(Path::of('src/Other.php'))))->toEqual(Unmeasured::duration())
        ->and(FirstRun::unmeasured()->seconds(Unit::file(Path::of('src/Money.php'))))->toEqual(Unmeasured::duration());
});

it('counts a covering test the map did not time as taking nothing', function (): void {
    $money = Path::of('src/Money.php');
    $untimed = CoverageMap::of()->covered($money, Line::of(3), TestId::of('MoneyTest::untimed'));
    $run = FirstRun::of($untimed, MutantSites::inFile($money, Line::of(3)), Seconds::of(2.0), ProcessCount::single(), FirstRunShape::of(overhead: 1.0));

    expect($run->seconds(Unit::file($money)))->toEqual(Seconds::of(2.0));
});

it('keeps what it measured for a cost model of an extension\'s to read', function (): void {
    $run = measuredMoney();

    expect($run->startUp())->toEqual(Seconds::of(2.0))
        ->and($run->processes())->toEqual(ProcessCount::of(2))
        ->and($run->sites()->count())->toBe(4)
        ->and(count($run->map()->tests()))->toBe(2)
        ->and(FirstRun::unmeasured()->startUp())->toEqual(Seconds::of(0.0))
        ->and(FirstRun::unmeasured()->processes())->toEqual(ProcessCount::single());
});

it('costs every file unit of a plan in time that grows with the plan, not with its square', function (): void {
    $costing = static function (int $files): Closure {
        $sites = MutantSites::none();
        $units = [];

        for ($at = 0; $at < $files; $at++) {
            $file = Path::of(sprintf('src/F%d.php', $at));
            $sites = $sites->and(MutantSites::inFile($file, Line::of(1), Line::of(2)));
            $units[] = Unit::file($file);
        }

        $run = FirstRun::of(CoverageMap::of(), $sites, Seconds::of(1.0), ProcessCount::single(), FirstRunShape::standard());

        return static function () use ($run, $units): int {
            $measured = 0;

            foreach ($units as $unit) {
                $measured += $run->seconds($unit) instanceof Seconds ? 1 : 0;
            }

            return $measured;
        };
    };

    expect($costing(10)())->toBe(10)
        ->and(Growth::of(250, $costing))->toBeLessThan(Growth::LINEAR);
});

/** A map where this many tests of this many seconds each cover line 3 of src/Money.php. */
function firstRunMap(int $tests, float $seconds): CoverageMap
{
    $map = CoverageMap::of();

    foreach (range(1, $tests) as $at) {
        $id = TestId::of(sprintf('MoneyTest::test%d', $at));
        $map = $map->covered(Path::of('src/Money.php'), Line::of(3), $id)->timed($id, Seconds::of($seconds));
    }

    return $map;
}

/** What a first run expects of src/Money.php: this many mutants on line 3, a second to start each, of this shape. */
function firstRunSeconds(CoverageMap $map, int $mutants, ProcessCount $processes, FirstRunShape $shape): float
{
    $money = Path::of('src/Money.php');
    $run = FirstRun::of($map, MutantSites::inFile($money, ...array_fill(0, $mutants, Line::of(3))), Seconds::of(1.0), $processes, $shape);
    $seconds = $run->seconds(Unit::file($money));

    return $seconds instanceof Seconds ? $seconds->seconds() : -1.0;
}

it('expects a mutant to spend its start-up, five covering tests at their average and a share of all of them for those that survive', function (): void {
    expect(firstRunSeconds(firstRunMap(100, 1.0), 10, ProcessCount::of(1), FirstRunShape::of(overhead: 1.0)))->toEqualWithDelta(10 * (1.0 + 0.98 * 5.0 + 0.02 * 100.0), 0.001);
});

it('expects no more of a killed mutant than its covering tests take', function (): void {
    expect(firstRunSeconds(firstRunMap(2, 1.0), 1, ProcessCount::of(1), FirstRunShape::of(overhead: 1.0)))->toEqualWithDelta(1.0 + 0.98 * 2.0 + 0.02 * 2.0, 0.001);
});

it('expects nothing of a mutant no test covers', function (): void {
    $money = Path::of('src/Money.php');
    $run = FirstRun::of(CoverageMap::of(), MutantSites::inFile($money, Line::of(3)), Seconds::of(1.0), ProcessCount::of(1), FirstRunShape::standard());

    expect($run->seconds(Unit::file($money)))->toEqual(Seconds::of(0.0));
});

it('takes its tests before a kill, the share that survive and a shard\'s overhead from what the config says', function (): void {
    $map = firstRunMap(100, 1.0);
    $at = static fn(FirstRunShape $shape): float => firstRunSeconds($map, 1, ProcessCount::of(1), $shape);

    expect($at(FirstRunShape::of(10, 0.0, 1.0)))->toEqualWithDelta(11.0, 0.001)
        ->and($at(FirstRunShape::of(5, 0.5, 1.0)))->toEqualWithDelta(1.0 + 2.5 + 50.0, 0.001)
        ->and($at(FirstRunShape::of(5, 0.0, 3.0)))->toEqualWithDelta(18.0, 0.001)
        ->and(FirstRunShape::of(new Absent(), new Absent(), new Absent()))->toEqual(FirstRunShape::standard())
        ->and(FirstRunShape::of(7, 0.1, 1.5)->shard(Seconds::of(2.0)))->toEqual(Seconds::of(3.0));
});

it('expects a shard to take a unit the whole suite covers past what such a unit measured, by much less than every test counted', function (): void {
    $estimate = firstRunSeconds(firstRunMap(2000, 340.0 / 2000), 342, ProcessCount::of(4), FirstRunShape::standard());

    expect($estimate)->toBeGreaterThan(655.0)->toBeLessThan(655.0 * 3.5);
});
