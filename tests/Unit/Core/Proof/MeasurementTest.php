<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is the time a shard spent mutating, which runner spent it, and when', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z'));
    $measured = Measurement::of(Seconds::of(540.5), 'pest', $at);

    expect($measured->spent())->toEqual(Seconds::of(540.5))
        ->and($measured->runner())->toBe('pest')
        ->and($measured->at())->toBe($at);
});

it('names the steps the shard\'s time went to where the shard timed any', function (): void {
    $measured = Measurement::of(Seconds::of(1.0), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));
    $steps = StepTimes::of(StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(1.0)));

    expect(count($measured->steps()))->toBe(0)
        ->and($measured->withSteps($steps)->steps())->toBe($steps)
        ->and($measured->withSteps($steps)->spent())->toEqual(Seconds::of(1.0));
});
