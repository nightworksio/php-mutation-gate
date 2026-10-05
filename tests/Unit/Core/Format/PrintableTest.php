<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Printable;

it('drops every control and format character but a tab and the ends of lines, so no escape sequence reaches a terminal or a log', function (string $text, string $printable): void {
    expect(Printable::text($text))->toBe($printable);
})->with([
    'a GitLab section that hides what follows' => ["\e[0Ksection_start:1:x[collapsed=true]\r\e[0Kverdict", "[0Ksection_start:1:x[collapsed=true]\r[0Kverdict"],
    'a Buildkite inline image' => ["\e]1338;url='https://attacker.example/p.png'\x07", "]1338;url='https://attacker.example/p.png'"],
    'an erase and a conceal' => ["failed\e[2K\e[8mpassed", 'failed[2K[8mpassed'],
    'a delete, a NUL and a backspace' => ["a\x7Fb\x00c\x08d", 'abcd'],
    'a C1 control' => ["a\u{9B}2Kb", 'a2Kb'],
    'a bidirectional override and a zero-width space' => ["fits\u{202E}stif\u{200B}", 'fitsstif'],
    'a tab and the ends of lines' => ["a\tb\r\nc\rd\ne", "a\tb\r\nc\rd\ne"],
]);

it('replaces invalid UTF-8 first, and keeps the text around it', function (): void {
    expect(Printable::text("\xC3\e[8mx"))->toBe('?[8mx');
});
