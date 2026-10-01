<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Processes;
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
    $map = CoverageMap::empty()
        ->covered($money, Line::of(3), $a)
        ->covered($money, Line::of(3), $b)
        ->covered($money, Line::of(7), $a)
        ->timed($a, Seconds::of(0.5))
        ->timed($b, Seconds::of(1.0));
    $sites = MutantSites::inFile($money, Line::of(3), Line::of(3), Line::of(7), Line::of(9))
        ->and(MutantSites::inFile(Path::of('src/Empty.php')));

    return FirstRun::of($map, $sites, Seconds::of(2.0), Processes::of(2));
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
    $untimed = CoverageMap::empty()->covered($money, Line::of(3), TestId::of('MoneyTest::untimed'));
    $run = FirstRun::of($untimed, MutantSites::inFile($money, Line::of(3)), Seconds::of(2.0), Processes::single());

    expect($run->seconds(Unit::file($money)))->toEqual(Seconds::of(2.0));
});

it('keeps what it measured for a cost model of an extension\'s to read', function (): void {
    $run = measuredMoney();

    expect($run->startUp())->toEqual(Seconds::of(2.0))
        ->and($run->processes())->toEqual(Processes::of(2))
        ->and($run->sites()->count())->toBe(4)
        ->and(count($run->map()->tests()))->toBe(2)
        ->and(FirstRun::unmeasured()->startUp())->toEqual(Seconds::of(0.0))
        ->and(FirstRun::unmeasured()->processes())->toEqual(Processes::single());
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

        $run = FirstRun::of(CoverageMap::empty(), $sites, Seconds::of(1.0), Processes::single());

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
