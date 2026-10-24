<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Visible;

it('writes a value as one line nothing in it can end: line breaks, returns, tabs and backslashes escaped', function (): void {
    expect(Visible::line("a\nmg verdict=passed"))->toBe('a\nmg verdict=passed')
        ->and(Visible::line("a\rb\tc\\d"))->toBe('a\rb\tc\\\\d')
        ->and(Visible::line("a\u{2028}next b\u{2029}c\u{85}d"))->toBe('a\u{2028}next b\u{2029}c\u{0085}d');
});

it('shows every control and format character as an escape, so none can act on a terminal or reorder text', function (): void {
    expect(Visible::line("\e]0;title\x07\e[31mred\u{9b}2J"))->toBe('\u{001b}]0;title\u{0007}\u{001b}[31mred\u{009b}2J')
        ->and(Visible::line("safe\u{202e}txt.php\u{200b}"))->toBe('safe\u{202e}txt.php\u{200b}')
        ->and(Visible::line('Übersicht 🙂 `$a < $b`'))->toBe('Übersicht 🙂 `$a < $b`');
});

it('keeps a text\'s line breaks and tabs, and escapes every other control, separator and format character', function (): void {
    expect(Visible::text("line one\n\tline two\e[2J\u{202e}\u{2028}"))->toBe("line one\n\tline two\\u{001b}[2J\\u{202e}\\u{2028}")
        ->and(Visible::text('a\\b'))->toBe('a\\b');
});
