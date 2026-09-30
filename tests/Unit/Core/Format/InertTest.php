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
]);

it('keeps a line that starts no command as it is', function (): void {
    expect(Inert::text("src/Money.php:7  LessThan  survived\n    Reproduce: a::b, c:d\n{\"a\": \"::x\"}"))
        ->toBe("src/Money.php:7  LessThan  survived\n    Reproduce: a::b, c:d\n{\"a\": \"::x\"}");
});

it('replaces invalid UTF-8 before it looks', function (): void {
    expect(Inert::text("\xC3::error::x"))->toBe('?::error::x')
        ->and(Inert::text("\xC3\n::error::x"))->toBe("?\n\\::error::x");
});
