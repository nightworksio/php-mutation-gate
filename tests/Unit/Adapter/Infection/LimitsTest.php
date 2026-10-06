<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\JUnit;
use NightWorksIO\MutationGate\Adapter\Infection\Limits;
use NightWorksIO\MutationGate\Adapter\Infection\PatchState;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Limits within bounds, with Infection patched or not, over classes that take these seconds. */
function limitsUnder(Seconds $cap, PatchState $state = PatchState::Missing, float $floor = 10.0): Limits
{
    $root = Scratch::directory();
    InfectionRun::coverage($root, '/p', [], ['Tests\MoneyTest' => 0.5, 'Tests\DrainTest' => 0.25, 'Tests\SlowTest' => 3.0], []);
    $junit = JUnit::at(DiskPath::of(sprintf('%s/junit.xml', $root)));
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::large'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\DrainTest::drains with data set #1'))
        ->covered(Path::of('src/Money.php'), Line::of(27), TestId::of('Tests\SlowTest::waits'));

    $bounds = LimitBounds::between(Seconds::of($floor), $cap);

    return Limits::of($map, $junit instanceof JUnit ? $junit : throw new RuntimeException('no JUnit'), $bounds, $state);
}

it('allows, unpatched, Infection\'s own 5 s and five times the covering classes\' time, each class counted once', function (): void {
    expect(limitsUnder(Seconds::of(10.0))->at(Path::of('src/Money.php'), Line::of(11)))->toEqual(Seconds::of(8.75));
});

it('allows no more than the cap', function (): void {
    expect(limitsUnder(Seconds::of(10.0))->at(Path::of('src/Money.php'), Line::of(27)))->toEqual(Seconds::of(10.0))
        ->and(limitsUnder(Seconds::of(8.0))->at(Path::of('src/Money.php'), Line::of(11)))->toEqual(Seconds::of(8.0));
});

it('allows, unpatched, a mutant no test covers 5 s, under no floor', function (): void {
    expect(limitsUnder(Seconds::of(10.0))->at(Path::of('src/Money.php'), Line::of(40)))->toEqual(Seconds::of(5.0));
});

it('allows, patched, the gate\'s 5 s and three times the covering classes\' time, within the bounds', function (): void {
    expect(limitsUnder(Seconds::of(300.0), PatchState::Applied, floor: 1.0)->at(Path::of('src/Money.php'), Line::of(11)))
        ->toEqual(Seconds::of(7.25))
        ->and(limitsUnder(Seconds::of(300.0), PatchState::Applied)->at(Path::of('src/Money.php'), Line::of(40)))
        ->toEqual(Seconds::of(10.0))
        ->and(limitsUnder(Seconds::of(12.0), PatchState::Applied)->at(Path::of('src/Money.php'), Line::of(27)))
        ->toEqual(Seconds::of(12.0));
});
