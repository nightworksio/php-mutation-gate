<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('adds each unit\'s time to its basis, and nothing for a unit expected to take none', function (): void {
    $estimate = ShardEstimate::none()
        ->with(Estimated::of(Seconds::of(2.0), CostBasis::Learned))
        ->with(Estimated::of(Seconds::of(3.0), CostBasis::Guessed))
        ->with(Estimated::of(Seconds::of(1.5), CostBasis::Learned))
        ->with(Estimated::of(Seconds::of(0.0), CostBasis::Measured));

    expect($estimate->units())->toEqual(Seconds::of(6.5))
        ->and($estimate->part(CostBasis::Learned))->toEqual(Seconds::of(3.5))
        ->and($estimate->part(CostBasis::Guessed))->toEqual(Seconds::of(3.0))
        ->and($estimate->part(CostBasis::Measured))->toEqual(Seconds::of(0.0))
        ->and(ShardEstimate::none()->with(Estimated::of(Seconds::of(0.0), CostBasis::Guessed)))
        ->toEqual(ShardEstimate::none());
});

it('keeps its opening run apart from its units\' time', function (): void {
    $estimate = ShardEstimate::none()->with(Estimated::of(Seconds::of(4.0), CostBasis::Guessed))->opening(Seconds::of(9.0));

    expect($estimate->units())->toEqual(Seconds::of(4.0))
        ->and($estimate->openingRun())->toEqual(Seconds::of(9.0))
        ->and(ShardEstimate::none()->openingRun())->toEqual(Seconds::of(0.0));
});

it('says what one unit\'s estimate is and rests on', function (): void {
    $estimated = Estimated::of(Seconds::of(2.5), CostBasis::Measured);

    expect([$estimated->seconds(), $estimated->basis()])->toEqual([Seconds::of(2.5), CostBasis::Measured]);
});
