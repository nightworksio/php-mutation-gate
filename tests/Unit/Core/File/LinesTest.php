<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;

it('holds nothing to begin with', function (): void {
    expect(Lines::none())->toHaveCount(0);
});

it('keeps each line once, in ascending order', function (): void {
    $lines = Lines::of(Line::of(9), Line::of(2), Line::of(9), Line::of(5));

    expect(array_map(static fn(Line $line): int => $line->number(), iterator_to_array($lines, preserve_keys: true)))->toBe([2, 5, 9])
        ->and($lines)->toHaveCount(3);
});

it('adds a line without changing the lines it came from', function (): void {
    $lines = Lines::of(Line::of(3));

    expect($lines->with(Line::of(1)))->toHaveCount(2)
        ->and($lines)->toHaveCount(1);
});

it('says whether it holds a line', function (): void {
    $lines = Lines::of(Line::of(3));

    expect($lines->has(Line::of(3)))->toBeTrue()
        ->and($lines->has(Line::of(4)))->toBeFalse();
});
