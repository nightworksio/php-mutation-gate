<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('keeps seconds between its floor and its most', function (float $seconds, float $kept): void {
    expect(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->kept(Seconds::of($seconds)))->toEqual(Seconds::of($kept));
})->with([
    'under the floor' => [4.0, 10.0],
    'the floor' => [10.0, 10.0],
    'between' => [42.0, 42.0],
    'the most' => [300.0, 300.0],
    'over the most' => [301.0, 300.0],
]);

it('has no floor up to a most, and keeps its floor when a retry raises its most', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->upToInstead(Seconds::of(600.0));

    expect([$bounds->floor(), $bounds->most()])->toEqual([Seconds::of(10.0), Seconds::of(600.0)])
        ->and(LimitBounds::upTo(Seconds::of(30.0))->kept(Seconds::of(0.5)))->toEqual(Seconds::of(0.5))
        ->and(LimitBounds::upTo(Seconds::of(30.0))->floor())->toEqual(Seconds::of(0.0));
});

it('keeps the silence limit of a mutator timeouts.tighter lists above the lower of the two floors, and any other above its own', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))
        ->tighterFor(TighterSilence::of(Seconds::of(7.0), 'RemoveArrayItem', 'Foreach_'));

    expect($bounds->silenceOf(RunnerMutatorName::of('Runner\Mutators\RemoveArrayItem'))->floor())->toEqual(Seconds::of(7.0))
        ->and($bounds->silenceOf(RunnerMutatorName::of('default/RemoveArrayItem'))->floor())->toEqual(Seconds::of(7.0))
        ->and($bounds->silenceOf(RunnerMutatorName::of('Foreach_'))->floor())->toEqual(Seconds::of(7.0))
        ->and($bounds->silenceOf(RunnerMutatorName::of('default/PlusToMinus'))->floor())->toEqual(Seconds::of(10.0))
        ->and($bounds->silenceOf(RunnerMutatorName::of('RemoveArrayItem'))->most())->toEqual(Seconds::of(300.0))
        ->and(LimitBounds::between(Seconds::of(5.0), Seconds::of(300.0))->tighterFor($bounds->tighter())->silenceOf(RunnerMutatorName::of('RemoveArrayItem'))->floor())
        ->toEqual(Seconds::of(5.0))
        ->and(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))->silenceOf(RunnerMutatorName::of('RemoveArrayItem'))->floor())->toEqual(Seconds::of(10.0));
});

it('measures no start-up until one is laid on it, and keeps one through every other change', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $measured = $bounds->startingIn(Seconds::of(2.5))
        ->tighterFor(TighterSilence::of(Seconds::of(7.0), 'RemoveArrayItem'))
        ->upToInstead(Seconds::of(600.0))
        ->silenceOf(RunnerMutatorName::of('RemoveArrayItem'));

    expect($bounds->startUp())->toBeInstanceOf(Unmeasured::class)
        ->and(LimitBounds::upTo(Seconds::of(30.0))->startUp())->toBeInstanceOf(Unmeasured::class)
        ->and($measured->startUp())->toEqual(Seconds::of(2.5))
        ->and([$measured->floor(), $measured->most()])->toEqual([Seconds::of(7.0), Seconds::of(600.0)]);
});

it('learns its floor as three times the start-up measured, at least a second and at most the most, and keeps the written floor where none was measured', function (float $startUp, float $floor): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(20.0))->learningFloor()
        ->startingIn($startUp > 0.0 ? Seconds::of($startUp) : Unmeasured::duration());

    expect($bounds->floor())->toEqual(Seconds::of($floor))
        ->and($bounds->learnsFloor())->toBeTrue()
        ->and($bounds->kept(Seconds::of(0.1)))->toEqual(Seconds::of($floor));
})->with([
    'a quick start-up' => [1.5, 4.5],
    'one under a third of a second' => [0.25, 1.0],
    'one past the most' => [9.0, 20.0],
    'none measured' => [0.0, 10.0],
]);

it('keeps a written floor whatever start-up was measured', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(20.0))->startingIn(Seconds::of(0.8));

    expect($bounds->floor())->toEqual(Seconds::of(10.0))
        ->and($bounds->learnsFloor())->toBeFalse();
});

it('allows three times the tests\' time unless a multiple is written, and a learned multiple once one is learned', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $learning = $bounds->multipliedBy(Learned::Learned);

    expect($bounds->multiple())->toEqual(Multiple::of(3.0))
        ->and($bounds->learnsMultiple())->toBeFalse()
        ->and($bounds->multipliedBy(Multiple::of(4.5))->multiple())->toEqual(Multiple::of(4.5))
        ->and($bounds->multipliedBy(Multiple::of(4.5))->learning(Multiple::of(7.0))->multiple())->toEqual(Multiple::of(4.5))
        ->and($learning->multiple())->toEqual(Multiple::of(3.0))
        ->and($learning->learnsMultiple())->toBeTrue()
        ->and($learning->learning(Multiple::of(7.0))->multiple())->toEqual(Multiple::of(7.0))
        ->and($learning->learning(Multiple::of(7.0))->upToInstead(Seconds::of(600.0))->multiple())->toEqual(Multiple::of(7.0))
        ->and($learning->learning(Multiple::of(7.0))->silenceOf(RunnerMutatorName::of('PlusToMinus'))->multiple())->toEqual(Multiple::of(7.0));
});
