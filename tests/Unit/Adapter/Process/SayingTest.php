<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Adapter\Process\Saying;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** A watch that tells this much progress. */
function sayingWatch(string $progress): ProcessWatch
{
    return new readonly class ($progress) implements ProcessWatch {
        public function __construct(private string $told)
        {
        }

        public function look(): void
        {
        }

        public function progress(): string
        {
            return $this->told;
        }
    };
}

/**
 * The lines a log at this file said, each without its line break.
 *
 * @return list<string>
 */
function saidAt(string $file): array
{
    return is_file($file) ? array_slice(explode("\n", (string) file_get_contents($file)), 0, -1) : [];
}

it('says nothing until a minute after the wait began, then once a minute: what ended, what runs, and the longest-running command', function (): void {
    $file = sprintf('%s/said.log', Scratch::directory());
    $commands = [ProcessCommand::of('/p', 'php', 'one.php'), ProcessCommand::of('/p', 'php', 'two.php'), ProcessCommand::of('/p', 'php', 'three.php')];
    $saying = Saying::on(ErrorOutputLog::to($file), new ScriptedClock('2026-10-26T10:00:00Z', 100, 110, 150, 160, 200, 260, 400))
        ->at([], $commands, 0, new Unwatched())
        ->at([1], $commands, 0, new Unwatched())
        ->at([1, 2], $commands, 1, new Unwatched());
    $quiet = saidAt($file);
    $saying = $saying->at([1, 2], $commands, 1, sayingWatch('3 of 8 mutants judged'))
        ->at([2], $commands, 2, new Unwatched());
    $once = saidAt($file);
    $saying->at([2], $commands, 2, new Unwatched())->at([], $commands, 3, new Unwatched());

    expect($quiet)->toBe([])
        ->and($once)->toBe([
            'mutation-gate: 1 of 3 processes ended, 2 running; the longest for 50s: php two.php; 3 of 8 mutants judged',
        ])
        ->and(saidAt($file))->toBe([
            'mutation-gate: 1 of 3 processes ended, 2 running; the longest for 50s: php two.php; 3 of 8 mutants judged',
            'mutation-gate: 2 of 3 processes ended, 1 running; the longest for 2m: php three.php',
        ]);
});

it('shows no more of a long command than a line holds', function (): void {
    $file = sprintf('%s/said.log', Scratch::directory());
    $long = ProcessCommand::of('/p', 'php', str_repeat('a', 300));
    Saying::on(ErrorOutputLog::to($file), new ScriptedClock('2026-10-26T10:00:00Z', 0, 0, 61))
        ->at([], [$long], 0, new Unwatched())
        ->at([0], [$long], 0, new Unwatched())
        ->at([0], [$long], 0, new Unwatched());
    $said = saidAt($file);

    expect($said)->toHaveCount(1)
        ->and(mb_strlen($said[0]))->toBeLessThan(260)
        ->and($said[0])->toContain('…');
});
