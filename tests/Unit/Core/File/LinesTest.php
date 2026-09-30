<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Tests\Support\Growth;

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

it('collects lines in time linear in their number, sorting once', function (): void {
    $lines = static function (int $size): Closure {
        $each = array_map(Line::of(...), range($size, 1, -1));

        return static fn(): Lines => Lines::of(...$each, ...$each);
    };

    expect($lines(10)())->toHaveCount(10)
        ->and([...$lines(10)()][0])->toEqual(Line::of(1))
        ->and(Growth::of(2500, $lines))->toBeLessThan(Growth::LINEAR);
});
