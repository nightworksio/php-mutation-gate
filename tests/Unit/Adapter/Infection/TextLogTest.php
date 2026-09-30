<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\TextLog;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A text log with an escaped mutant, two skipped ones and an uncovered one. */
$log = static function (): TextLog {
    $root = Scratch::directory();
    InfectionRun::text(sprintf('%s/infection.log', $root), [
        'Escaped' => [['/p/src/Money.php', 16, 'GreaterThan', 'e1', 'return $a > 1;', 'return $a >= 1;']],
        'Timed Out' => [],
        'Skipped' => [
            ['/p/src/Slow.php', 11, 'Minus', 's1', 'return $a - 2;', 'return $a + 2;'],
            ['/p/src/Slow.php', 20, 'Plus', 's2', 'return $a + 2;', 'return $a - 2;'],
        ],
        'Not Covered' => [['/p/src/Money.php', 21, 'Minus', 'u1', 'return $a - 1;', 'return $a + 1;']],
    ]);

    return TextLog::at(sprintf('%s/infection.log', $root));
};

it('names each mutant under its heading, with its file, line, mutator, native id and diff', function () use ($log): void {
    expect($log()->under(TextLog::SKIPPED))->toBe([
        [
            'status' => 'Skipped',
            'file' => '/p/src/Slow.php',
            'line' => 11,
            'mutator' => 'Minus',
            'id' => 's1',
            'diff' => InfectionRun::diff('return $a - 2;', 'return $a + 2;'),
        ],
        [
            'status' => 'Skipped',
            'file' => '/p/src/Slow.php',
            'line' => 20,
            'mutator' => 'Plus',
            'id' => 's2',
            'diff' => InfectionRun::diff('return $a + 2;', 'return $a - 2;'),
        ],
    ])->and($log()->count(TextLog::SKIPPED))->toBe(2)
        ->and($log()->count('Timed Out'))->toBe(0)
        ->and($log()->count('Not Covered'))->toBe(1)
        ->and($log()->under('Escaped')[0]['diff'])->toBe(InfectionRun::diff('return $a > 1;', 'return $a >= 1;'));
});

it('gives the native id of a mutant it names, found by where it is, its mutator and its change', function () use ($log): void {
    $diff = InfectionRun::diff('return $a - 1;', 'return $a + 1;');

    expect($log()->idOf('/p/src/Money.php', 21, 'Minus', $diff))->toBe('u1')
        ->and($log()->idOf('/p/src/Money.php', 21, 'Minus', "@@ @@\n-return \$a   - 1;\n+return \$a + 1;"))->toBe('u1')
        ->and($log()->idOf('/p/src/Money.php', 21, 'Plus', $diff))->toBe('')
        ->and($log()->idOf('/p/src/Money.php', 22, 'Minus', $diff))->toBe('')
        ->and($log()->idOf('/p/src/Other.php', 21, 'Minus', $diff))->toBe('')
        ->and($log()->idOf('/p/src/Money.php', 21, 'Minus', InfectionRun::diff('return $a - 1;', 'return $a;')))->toBe('');
});

it('names no mutant where no run wrote a log', function (): void {
    $log = TextLog::at('/nowhere/infection.log');

    expect($log->count(TextLog::SKIPPED))->toBe(0)
        ->and($log->under(TextLog::SKIPPED))->toBe([]);
});

it('reads a heading only with its underline, and a line before any mutant as no one\'s', function (): void {
    $text = implode("\n", [
        'Skipped mutants:',
        'not an underline',
        '1) /p/A.php:3    [M] Plus [ID] a1',
        '',
        'Escaped mutants:',
        '================',
        'stray line',
        '1) /p/A.php:4    [M] Plus [ID] a2',
        '@@ @@',
        '-$a + 1',
        '+$a - 1',
    ]);
    $log = TextLog::read($text);

    expect($log->count(TextLog::SKIPPED))->toBe(0)
        ->and($log->count(''))->toBe(1)
        ->and($log->under('Escaped'))->toBe([[
            'status' => 'Escaped',
            'file' => '/p/A.php',
            'line' => 4,
            'mutator' => 'Plus',
            'id' => 'a2',
            'diff' => "@@ @@\n-\$a + 1\n+\$a - 1",
        ]])
        ->and($log->under('')[0]['diff'])->toBe('');
});
