<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Tally;

it('holds how many of its counted mutants were killed', function (): void {
    $tally = Tally::of(3, 4);

    expect($tally->killed())->toBe(3)
        ->and($tally->counted())->toBe(4);
});

it('counts nothing when none is given', function (): void {
    expect(Tally::none()->killed())->toBe(0)
        ->and(Tally::none()->counted())->toBe(0);
});

it('adds another tally, killed to killed and counted to counted', function (): void {
    $sum = Tally::of(3, 4)->plus(Tally::of(5, 9));

    expect($sum->killed())->toBe(8)
        ->and($sum->counted())->toBe(13);
});
