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

it('makes text from outside one plain line, with no control character and valid UTF-8', function (): void {
    expect(Fit::plain("  one\n\ntwo\r\n\tthree  "))->toBe('one two three')
        ->and(Fit::plain("\e[31mred\e[0m\x07"))->toBe('[31mred[0m')
        ->and(Fit::plain("ok\xC3(\u{85}"))->toBe('ok?(')
        ->and(Fit::plain("left\u{202E}thgir\u{200B}\u{FEFF}"))->toBe('leftthgir');
});

it('drops every format character, so no text it is shown in is reordered or hidden', function (): void {
    expect(Fit::plain("it adds\u{202E}lave\u{202C}"))->toBe('it addslave')
        ->and(Fit::plain("it\u{200B} adds"))->toBe('it adds');
});
