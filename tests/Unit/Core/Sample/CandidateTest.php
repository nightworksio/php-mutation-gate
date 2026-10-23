<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('holds a unit, the mutants it is estimated to hold, and its last result\'s tally', function (): void {
    $unit = Unit::file(Path::of('src/A.php'));
    $last = Tally::of(5, 6);
    $candidate = Candidate::of($unit, 6, $last);

    expect($candidate->unit())->toBe($unit)
        ->and($candidate->mutants())->toBe(6)
        ->and($candidate->last())->toBe($last)
        ->and(Candidate::of($unit, 6, NotGiven::value())->last())->toBeInstanceOf(NotGiven::class);
});
