<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Migration\LineDiff;

/** The diff of two texts of a letter a line, each line written as its mark and its letter, `|` between them. */
function lineDiffOf(string $old, string $new): string
{
    return implode('|', array_map(
        static fn(array $line): string => implode('', $line),
        LineDiff::of(str_split($old), str_split($new)),
    ));
}

it('marks the lines two texts share and those each holds alone, by the longest run they share', function (): void {
    expect([
        lineDiffOf('cac', 'b'),
        lineDiffOf('c', 'ababbb'),
        lineDiffOf('cc', 'cbbc'),
        lineDiffOf('bb', 'bca'),
        lineDiffOf('cabc', 'ccb'),
        lineDiffOf('bbca', 'cbc'),
    ])->toBe([
        '-c|-a|-c|+b',
        '-c|+a|+b|+a|+b|+b|+b',
        ' c|+b|+b| c',
        ' b|-b|+c|+a',
        ' c|-a|-b| c|+b',
        '-b|+c| b| c|-a',
    ]);
});

it('marks a line gone before one added in its place, and the rest of the longer text after the shorter ends', function (): void {
    expect([lineDiffOf('a', 'b'), lineDiffOf('abc', 'a'), lineDiffOf('a', 'abc'), lineDiffOf('', 'a'), lineDiffOf('a', '')])
        ->toBe(['-a|+b', ' a|-b|-c', ' a|+b|+c', '+a', '-a']);
});
