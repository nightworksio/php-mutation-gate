<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Measured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Psr\Clock\ClockInterface;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A clock that reads what a function says, in seconds from any start.
 *
 * @param Closure(): float $seconds
 */
function processesClock(Closure $seconds): ClockInterface
{
    return new readonly class ($seconds) implements ClockInterface {
        /** @param Closure(): float $seconds */
        public function __construct(private Closure $seconds)
        {
        }

        public function now(): DateTimeImmutable
        {
            $read = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', ($this->seconds)()));

            return $read instanceof DateTimeImmutable ? $read : new DateTimeImmutable('@0');
        }
    };
}

it('runs a program in its directory, with its environment, and keeps both of its outputs', function (): void {
    $directory = (string) realpath(Scratch::directory());
    $command = ProcessCommand::of($directory, PHP_BINARY, '-r', 'echo getcwd(), " ", getenv("GATE"); fwrite(STDERR, "!");')
        ->with(Environment::telling('GATE', 'on'));
    $ran = new LocalProcesses(new SystemClock())->run($command);

    expect($ran)->toEqual(Ran::exited(0, sprintf('%s on!', $directory))->took(Measured::of($ran)));
});

it('says a program that exits with a failure did not succeed, and its exit code', function (): void {
    $ran = new LocalProcesses(new SystemClock())->run(ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'echo "no"; exit(3);'));

    expect($ran)->toEqual(Ran::exited(3, 'no')->took(Measured::of($ran)));
});

it('stops a program at its deadline with every process it started, keeping what it printed', function (): void {
    $pids = sprintf('%s/child.pid', Scratch::directory());
    $script = sprintf(
        'echo "started"; $child = proc_open([PHP_BINARY, "-r", "sleep(30);"], [], $pipes); file_put_contents(%s, proc_get_status($child)["pid"]); sleep(30);',
        var_export($pids, return: true),
    );
    $ran = new LocalProcesses(new SystemClock())->run(
        ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', $script)->within(Seconds::of(3.0)),
    );
    exec(sprintf('ps -o stat= -p %d', (int) file_get_contents($pids)), $state);

    expect([$ran->wasStopped(), $ran->output()])->toBe([true, 'started'])
        ->and(Measured::of($ran)->seconds())->toBeLessThan(20.0)
        ->and(array_filter($state, static fn(string $line): bool => ! str_starts_with(trim($line), 'Z')))->toBe([]);
});

it('measures a deadline on its clock', function (): void {
    $command = ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'sleep(20);')->within(Seconds::of(0.0));

    $ran = new LocalProcesses(new SystemClock())->run($command);

    expect($ran)->toEqual(Ran::stopped('')->took(Measured::of($ran)));
});

it('measures how long a program ran on its clock, from its start to its end', function (): void {
    $reads = 0;
    // The clock reads 10 when the program starts, and 12.5 at every look after.
    $clock = processesClock(static function () use (&$reads): float {
        return $reads++ === 0 ? 10.0 : 12.5;
    });
    $ran = new LocalProcesses($clock)->run(ProcessCommand::of(Scratch::directory(), PHP_BINARY, '-r', 'echo "ok";'));

    expect(Measured::of($ran))->toEqual(Seconds::of(2.5))
        ->and($ran->output())->toBe('ok');
});

it('says a program that cannot be started did not succeed, and why', function (): void {
    $ran = new LocalProcesses(new SystemClock())->run(ProcessCommand::of('/nowhere/at/all', PHP_BINARY, '-v'));

    expect($ran->succeeded())->toBeFalse()
        ->and($ran->output())->toContain('/nowhere/at/all');
});

it('starts each command, in turn, in a free place, never two in one place at once', function (): void {
    $directory = (string) realpath(Scratch::directory());
    // The slow command ends only once the three quick ones have each marked that they ran, however slowly they start.
    $slow = ProcessCommand::of(
        $directory,
        PHP_BINARY,
        '-r',
        'while (strlen((string) @file_get_contents("ran")) < 3) { usleep(10000); } echo getenv("TEST_TOKEN");',
    )->within(Seconds::of(30.0));
    $quick = ProcessCommand::of($directory, PHP_BINARY, '-r', 'file_put_contents("ran", "x", FILE_APPEND); echo getenv("TEST_TOKEN");');

    $ends = new LocalProcesses(new SystemClock())->sideBySide(WorkerSlots::of(ProcessCount::of(2), 'run'), Unlimited::time(), $slow, $quick, $quick, $quick);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))->toBe(['1', '2', '2', '2']);
});

it('starts no command once its time to start has run out, and ends those started', function (): void {
    $directory = Scratch::directory();
    $reads = 0;
    // The clock reads 0 for the start of the time and the first start, then 100 at every read after.
    $clock = processesClock(static function () use (&$reads): float {
        return $reads++ < 2 ? 0.0 : 100.0;
    });
    $said = ProcessCommand::of($directory, PHP_BINARY, '-r', 'echo "said";');

    $ends = new LocalProcesses($clock)->sideBySide(WorkerSlots::alone(), Seconds::of(50.0), $said, $said, $said);

    expect(array_map(static fn(Ran $ran): string => $ran->output(), [...$ends]))->toBe(['said']);
});
