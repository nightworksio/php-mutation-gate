<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Fit;

it('keeps lines that fit as they are', function (): void {
    expect(Fit::lines(['one', 'two'], 7))->toBe("one\ntwo");
});

it('keeps the whole lines that fit with a line saying how many it left out', function (): void {
    $lines = ['aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc', 'dddddddddd'];

    expect(Fit::lines($lines, 35))->toBe("aaaaaaaaaa\nbbbbbbbbbb\nAnd 2 more.")
        ->and(Fit::lines($lines, 42))->toBe("aaaaaaaaaa\nbbbbbbbbbb\nAnd 2 more.")
        ->and(Fit::lines($lines, 43))->toBe(implode("\n", $lines))
        ->and(Fit::lines($lines, 11))->toBe('And 4 more.')
        ->and(mb_strlen(Fit::lines($lines, 22)))->toBeLessThanOrEqual(22);
});

it('cuts one line to its limit, ending where it was cut', function (): void {
    expect(Fit::line('abcdef', 6))->toBe('abcdef')
        ->and(Fit::line('abcdefg', 6))->toBe('abcde…')
        ->and(Fit::line('ééééééé', 6))->toBe('ééééé…');
});

it('says how many it left out', function (): void {
    expect(Fit::more(3))->toBe('And 3 more.');
});
