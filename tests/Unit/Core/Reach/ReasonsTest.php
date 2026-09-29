<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

it('holds the decisions in the order they were made', function (): void {
    $reasons = Reasons::of(Reason::that('first'))->with(Reason::that('second'));

    expect($reasons)->toHaveCount(2)
        ->and(array_map(static fn(Reason $reason): string => $reason->text(), iterator_to_array($reasons, preserve_keys: true)))
        ->toBe(['first', 'second']);
});

it('numbers the decisions from nought, whatever they were named', function (): void {
    $reasons = Reasons::of(...['b' => Reason::that('b'), 'a' => Reason::that('a')]);

    expect(array_map(static fn(Reason $reason): string => $reason->text(), iterator_to_array($reasons, preserve_keys: true)))
        ->toBe(['b', 'a']);
});

it('leaves the decisions it came from as they were', function (): void {
    $reasons = Reasons::of();
    $reasons->with(Reason::that('first'));

    expect($reasons)->toHaveCount(0);
});
