<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * How a child forked in a fresh PHP process ended once it ran this code, which may write to `$said`: the signal that
 * ended it, or none, with the status it exited with, and what it wrote.
 *
 * @return array{int, int, string}
 */
function ownEndOf(string $child): array
{
    $said = sprintf('%s/said', Scratch::directory());
    $answer = json_decode(FreshProcess::answer(sprintf(<<<'CODE'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;

        $said = %s;
        $child = pcntl_fork();

        if ($child === 0) {
            %s
        }

        pcntl_waitpid($child, $status);
        echo json_encode([
            pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0,
            pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
            is_file($said) ? file_get_contents($said) : '',
        ]);
        CODE, var_export($said, return: true), $child)), associative: true);

    return is_array($answer) && is_int($answer[0] ?? null) && is_int($answer[1] ?? null) && is_string($answer[2] ?? null)
        ? [$answer[0], $answer[1], $answer[2]]
        : [0, -1, ''];
}

it('runs what a process asked for, in the order asked, before it signals itself, and ends by that signal', function (): void {
    expect(ownEndOf(<<<'CODE'
        OwnEnd::first(static fn() => file_put_contents($said, 'first ', FILE_APPEND));
        OwnEnd::first(static fn() => file_put_contents($said, 'second', FILE_APPEND));
        OwnEnd::by(SIGKILL);
        CODE))->toBe([SIGKILL, -1, 'first second']);
})->skip(fn(): bool => ! Forking::isAvailable(), 'the process forks');

it('ends by its signal a process whose closure failed, never returning to its caller', function (): void {
    expect(ownEndOf(<<<'CODE'
        OwnEnd::first(static fn() => throw new RuntimeException('unwritten'));

        try {
            OwnEnd::by(SIGKILL);
        } finally {
            file_put_contents($said, 'returned');
        }
        CODE))->toBe([SIGKILL, -1, '']);
})->skip(fn(): bool => ! Forking::isAvailable(), 'the process forks');

it('ends by exit a process its own signal did not end, never returning to its caller', function (): void {
    expect(ownEndOf(<<<'CODE'
        pcntl_signal(SIGUSR2, static function (): void {
        });
        OwnEnd::first(static fn() => file_put_contents($said, 'ran', FILE_APPEND));
        OwnEnd::by(SIGUSR2);
        file_put_contents($said, ' returned', FILE_APPEND);
        CODE))->toBe([0, OwnEnd::OUTLIVED, 'ran']);
})->skip(fn(): bool => ! Forking::isAvailable(), 'the process forks');
