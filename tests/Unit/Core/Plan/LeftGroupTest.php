<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftGroup;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftShare;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;

function leftGroupMutant(string $native): LeftMutant
{
    return LeftMutant::of(MutantId::hash(Path::of('src/Money.php'), 'Minus', $native, 0), $native, Path::of('src/Money.php'));
}

it('takes its own runs\' seconds, of every mutant no stream judged, shared by the job\'s processes, on top of both passes', function (): void {
    $forks = LeftForks::alone(30.0)->and(LeftForks::alone(20.0))->and(LeftForks::atEveryTest(8.0));
    $group = LeftGroup::of([leftGroupMutant('a'), leftGroupMutant('b')], ['MoneyTest'], ['MoneyTest' => 10.0], $forks);
    $wall = StreamWall::of(2, 0.5);

    expect($forks->alone)->toBe(50.0)
        ->and($forks->covering)->toBe(8.0)
        ->and(LeftForks::atKiller(3.0, 4.0)->alone)->toBe(0.0)
        ->and($group->alone())->toBe(50.0)
        ->and($group->wall($wall))->toBe(10.0 + 2.0 + 25.0)
        ->and($group->wall($wall))->toBe($group->first($wall) + $group->second($wall) + $wall->alone(50.0));
});

it('prices a share at its groups\' own runs together, beside its passes', function (): void {
    $wall = StreamWall::of(2, 0.0);
    $one = LeftGroup::of([leftGroupMutant('a')], ['MoneyTest'], ['MoneyTest' => 10.0], LeftForks::alone(30.0));
    $other = LeftGroup::of([leftGroupMutant('b')], ['MoneyTest'], ['MoneyTest' => 10.0], LeftForks::alone(20.0));
    $share = LeftShare::of($one, $wall);

    expect($share->wall())->toBe(10.0 + 15.0)
        ->and($share->with($other, 0.0)->wall())->toBe(10.0 + 25.0);
});

it('spreads own runs over a job\'s processes, scaled by no pass\'s factor, and holds its processes\' seconds in a wall second', function (): void {
    expect(StreamWall::of(4, 0.01)->alone(120.0))->toBe(30.0)
        ->and(StreamWall::of(1, 0.01)->alone(120.0))->toBe(120.0)
        ->and(StreamWall::of(4, 0.01)->worth(30.0))->toBe(120.0)
        ->and(StreamWall::of(0, 1.0)->worth(30.0))->toBe(30.0);
});

it('times its covering test files once each, a file the map timed no test of at nothing, and takes the longest of them', function (): void {
    $wall = StreamWall::of(2, 0.5);
    $timed = ['QuickTest' => 0.25, 'SlowTest' => 0.75];
    $group = LeftGroup::of([leftGroupMutant('a')], ['QuickTest', 'SlowTest', 'QuickTest', 'UntimedTest'], $timed, LeftForks::atEveryTest(0.0));
    $none = LeftGroup::of([leftGroupMutant('b')], [], $timed, LeftForks::atEveryTest(0.0));

    expect($group->first($wall))->toBe($wall->first(1.0, 0.75, 0.0))
        ->and($group->first($wall))->not->toBe($wall->first(1.0, 1.0, 0.0))
        ->and($group->first(StreamWall::of(1, 0.5)))->toBe(1.0)
        ->and($none->first($wall))->toBe($wall->first(0.0, 0.0, 0.0))
        ->and($none->alone())->toBe(0.0)
        ->and($group->files())->toBe(['QuickTest' => true, 'SlowTest' => true, 'UntimedTest' => true])
        ->and($none->files())->toBe([]);
});

it('adds for another group only the test files it does not run yet, one the map timed no test of at nothing', function (): void {
    $timed = ['MoneyTest' => 0.5, 'OtherTest' => 0.25];
    $one = LeftGroup::of([leftGroupMutant('a')], ['MoneyTest'], $timed, LeftForks::atEveryTest(0.0));
    $other = LeftGroup::of([leftGroupMutant('b')], ['MoneyTest', 'OtherTest', 'UntimedTest'], $timed, LeftForks::atEveryTest(0.0));

    expect($one->added($other, $timed))->toBe(0.25)
        ->and($other->added($one, $timed))->toBe(0.0)
        ->and($one->added($one, $timed))->toBe(0.0)
        ->and(LeftGroup::addedTo([], $other, $timed))->toBe(0.75);
});

it('forks a mutant no stream judged at no killer and no covering test, its own run alone taking its seconds', function (): void {
    $alone = LeftForks::alone(3.0);

    expect([$alone->killers, $alone->covering, $alone->alone])->toBe([0.0, 0.0, 3.0]);
});
