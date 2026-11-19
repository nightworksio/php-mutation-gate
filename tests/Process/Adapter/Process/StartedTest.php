<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = ['holds:src/Adapter/Process/Started.php'];

afterEach(function (): void {
    Scratch::sweep();
});

it('lets go of the commands its ends so far name as settled: stopping those that run, and never starting those that wait', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $long = ProcessCommand::of($directory, PHP_BINARY, '-r', 'sleep(30); echo "long";')->within(Seconds::of(60.0));
    $quick = ProcessCommand::of($directory, PHP_BINARY, '-r', 'echo "quick";');
    $waiting = ProcessCommand::of($directory, PHP_BINARY, '-r', 'file_put_contents("started", "x"); echo "waiting";');
    $asked = [];
    $started = new LocalProcesses(new SystemClock())
        ->started(WorkerSlots::of(ProcessCount::of(2), 'settle'), Unlimited::time(), $long, $quick, $waiting);
    $from = microtime(as_float: true);

    $ends = $started->endsSettling(static function (array $ended) use (&$asked): array {
        $asked[] = array_keys($ended);

        return array_key_exists(1, $ended) ? [0, 2] : [];
    });

    expect(microtime(as_float: true) - $from)->toBeLessThan(20.0)
        ->and(array_map(static fn(Ran $ran): array => [$ran->output(), $ran->wasStopped() ? 'stopped' : 'ended'], [...$ends]))
        ->toBe([['', 'stopped'], ['quick', 'ended'], ['', 'stopped']])
        ->and(is_file(sprintf('%s/started', $directory)))->toBeFalse()
        ->and($asked[0])->toContain(1);
})->group(...$holds);

it('awaits every command where none is settled, as ends() does', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $quick = ProcessCommand::of($directory, PHP_BINARY, '-r', 'echo "quick";');

    $ends = new LocalProcesses(new SystemClock())
        ->started(WorkerSlots::of(ProcessCount::of(1), 'settle'), Unlimited::time(), $quick, $quick)
        ->endsSettling(static fn(): array => []);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))->toBe(['quick', 'quick']);
})->group(...$holds);
