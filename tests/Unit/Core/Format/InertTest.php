<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Inert;

it('starts no workflow command on any line, however it is indented', function (string $text, string $inert): void {
    expect(Inert::text($text))->toBe($inert);
})->with([
    'at the start' => ['::error::injected', '\::error::injected'],
    'after a carriage return alone' => ["x\r::add-mask::s", "x\r\\::add-mask::s"],
    'after a carriage return and a line feed' => ["x\r\n::error::y", "x\r\n\\::error::y"],
    'after a line feed and a carriage return' => ["x\n\r::error::y", "x\n\r\\::error::y"],
    'after a line break' => ["Rejected by phpstan: x\n::error file=a,line=1::injected", "Rejected by phpstan: x\n\\::error file=a,line=1::injected"],
    'indented' => ["    Why: no\n  \t::warning::x", "    Why: no\n  \t\\::warning::x"],
    'after a no-break space' => ["\u{A0}\u{2003}::notice::x", "\u{A0}\u{2003}\\::notice::x"],
    'in the older form, anywhere' => ['text ##[error]injected', 'text ## [error]injected'],
    'in Azure\'s form, anywhere' => ['x ##vso[task.setvariable variable=a]b', 'x ##vso [task.setvariable variable=a]b'],
    'encoded' => ['%0A::error::x', '%0A::error::x'],
    'TeamCity\'s, anywhere' => ["x ##teamcity[buildStatus text='passed']", "x ##teamcity [buildStatus text='passed']"],
    'after the colour a console writes' => ["\e[32m ::error::x\e[39m", "\e[32m \\::error::x\e[39m"],
    'after two colours and white space' => ["  \e[1m\e[37;41m::error::x", "  \e[1m\e[37;41m\\::error::x"],
]);

it('keeps a line that starts no command as it is', function (): void {
    expect(Inert::text("src/Money.php:7  LessThan  survived\n    Reproduce: a::b, c:d\n{\"a\": \"::x\"}"))
        ->toBe("src/Money.php:7  LessThan  survived\n    Reproduce: a::b, c:d\n{\"a\": \"::x\"}");
});

it('replaces invalid UTF-8 before it looks', function (): void {
    expect(Inert::text("\xC3::error::x"))->toBe('?::error::x')
        ->and(Inert::text("\xC3\n::error::x"))->toBe("?\n\\::error::x");
});

it('makes a command inert as each runner reads it, whatever white space, case or invisible character comes first', function (string $text, string $inert): void {
    expect(Inert::text($text))->toBe($inert);
})->with([
    'after a next-line character, which .NET trims, a control character dropped' => ["\u{85}::error::x", '\\::error::x'],
    'after a line separator and an ideographic space' => ["\u{2028}\u{3000}::error::x", "\u{2028}\u{3000}\\::error::x"],
    'after a vertical tab and a form feed, which .NET trims' => ["\x0B\x0C::error::x", '\\::error::x'],
    'after a zero-width space, which ICU ignores' => ["\u{200B}::error::x", '\\::error::x'],
    'with a zero-width space inside' => ["#\u{200B}#[error]x", '## [error]x'],
    'Azure\'s, in capitals' => ['x ##VSO[task.setvariable variable=a]b', 'x ##VSO [task.setvariable variable=a]b'],
    'Azure\'s, in mixed case' => ['x ##Vso[task.complete result=Succeeded]', 'x ##Vso [task.complete result=Succeeded]'],
    'one that stops every command' => ['::stop-commands::resume-token', '\\::stop-commands::resume-token'],
    'one that masks a value' => ['  ::add-mask::secret', '  \\::add-mask::secret'],
]);

it('keeps the escape a colour starts with, and drops every other control and format character', function (): void {
    expect(Inert::text("\e[32mpassed\e[39m\x07\u{9B}2K\u{202E}"))->toBe("\e[32mpassed\e[39m2K");
});
