<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('counts the checks of a mutator and how many rejected its mutant', function (): void {
    $rate = RejectionRate::unchecked('Plus')->passed()->rejected()->passed();

    expect($rate->mutator())->toBe('Plus')
        ->and($rate->checks())->toBe(3)
        ->and($rate->rejections())->toBe(1)
        ->and(RejectionRate::of('Minus', 50, 5)->checks())->toBe(50)
        ->and(RejectionRate::of('Minus', 50, 5)->rejections())->toBe(5);
});

it('saves the tests\' time for the share of mutants it rejects, and nothing before its first check', function (): void {
    expect(RejectionRate::of('Plus', 4, 1)->saving(Seconds::of(2.0)))->toEqual(Seconds::of(0.5))
        ->and(RejectionRate::of('Plus', 4, 0)->saving(Seconds::of(2.0)))->toEqual(Seconds::of(0.0))
        ->and(RejectionRate::unchecked('Plus')->saving(Seconds::of(2.0)))->toEqual(Seconds::of(0.0));
});

it('adds another rate of its mutator to it: every check of both, and every rejection', function (): void {
    expect(RejectionRate::of('Plus', 4, 1)->plus(RejectionRate::of('Plus', 6, 2)))->toEqual(RejectionRate::of('Plus', 10, 3));
});
