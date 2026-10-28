<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\ErrorOutputLog;
use NightWorksIO\MutationGate\Adapter\Process\Saying;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

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

it('says nothing until a minute after it began, then once a minute: what ended, what runs, and the longest-running command', function (): void {
    $file = sprintf('%s/said.log', Scratch::directory());
    $log = ErrorOutputLog::to($file);
    $said = static fn(): array => is_file($file) ? array_slice(explode("\n", (string) file_get_contents($file)), 0, -1) : [];
    $commands = [ProcessCommand::of('/p', 'php', 'one.php'), ProcessCommand::of('/p', 'php', 'two.php'), ProcessCommand::of('/p', 'php', 'three.php')];
    $saying = Saying::from($log, 100.0)
        ->at(110.0, [1], $commands, 0, new Unwatched())
        ->at(150.0, [1, 2], $commands, 1, new Unwatched());
    $quiet = $said();
    $saying = $saying->at(160.0, [1, 2], $commands, 1, sayingWatch('3 of 8 mutants judged'))
        ->at(200.0, [2], $commands, 2, new Unwatched());
    $once = $said();
    $saying->at(260.0, [2], $commands, 2, new Unwatched())->at(400.0, [], $commands, 3, new Unwatched());

    expect($quiet)->toBe([])
        ->and($once)->toBe([
            "mutation-gate: 1 of 3 processes ended, 2 running; the longest for 50s: php two.php; 3 of 8 mutants judged",
        ])
        ->and($said()[1])->toBe("mutation-gate: 2 of 3 processes ended, 1 running; the longest for 2m: php three.php")
        ->and($said())->toHaveCount(2);
});

it('shows no more of a long command than a line holds', function (): void {
    $file = sprintf('%s/said.log', Scratch::directory());
    $log = ErrorOutputLog::to($file);
    $said = static fn(): array => is_file($file) ? array_slice(explode("\n", (string) file_get_contents($file)), 0, -1) : [];
    $long = ProcessCommand::of('/p', 'php', str_repeat('a', 300));
    Saying::from($log, 0.0)->at(0.0, [0], [$long], 0, new Unwatched())->at(61.0, [0], [$long], 0, new Unwatched());

    expect(mb_strlen($said()[0]))->toBeLessThan(260)
        ->and($said()[0])->toContain('…');
});
