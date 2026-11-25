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
