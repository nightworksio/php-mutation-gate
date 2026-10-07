<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Silence;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    putenv(GateVariable::MutantFloor->value);
    putenv(GateVariable::MutantCap->value);
    putenv(GateVariable::Results->value);
    MutantTime::remember([]);
    Scratch::sweep();
});

/**
 * An own run that beats once on its error output, after this long, and then
 * prints a dot on its output every tenth of a second for half a minute.
 */
function silenceRun(float $before): Process
{
    $process = new Process([PHP_BINARY, '-r', sprintf(
        'usleep(%d); fwrite(STDERR, %s); for ($i = 0; $i < 300; $i++) { echo "."; usleep(100000); }',
        (int) ($before * 1_000_000),
        var_export(Silence::BEAT, return: true),
    )]);
    $process->start();

    return $process;
}

/**
 * Waits until the run's beat is in its error output, leaving it unread for
 * Silence. Output the process gave before the wait begins never reaches the
 * wait's callback, so the callback reads the whole error output, as each dot
 * comes.
 */
function silenceBeaten(Process $process): void
{
    $process->waitUntil(static fn(): bool => str_contains($process->getErrorOutput(), Silence::BEAT));
}

beforeEach(function (): void {
    putenv(sprintf('%s=%s', GateVariable::MutantFloor->value, '1'));
    putenv(sprintf('%s=%s', GateVariable::MutantCap->value, '300'));
    MutantTime::remember(['testResults' => ['T::a' => ['size' => 'unknown', 'status' => 'success', 'time' => 1.0]]]);
});

it('holds a run to no limit before its tests begin, then stops it once no test has finished for its limit, recording it', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));
    $process = silenceRun(0.2);
    Silence::watch($process, ['T::a'], '/tmp/mutations/abc', '--filter="T::a"', 'P\\Plus');
    $start = microtime(as_float: true);

    Silence::check($process, $start + 100.0);
    silenceBeaten($process);
    Silence::check($process, $start + 1.0);
    Silence::check($process, $start + 8.9);

    expect($process->isRunning())->toBeTrue()
        ->and(static fn() => Silence::check($process, $start + 9.1))->toThrow(ProcessTimedOutException::class)
        ->and($process->isRunning())->toBeFalse()
        ->and(file_get_contents($results))->toBe(RecordLine::silent('/tmp/mutations/abc', 8.0));
});

it('never stops a run it does not watch, one watched again under tests the map did not time, nor one whose filter was left out', function (): void {
    $unwatched = silenceRun(0.0);
    $untimed = silenceRun(0.0);
    $unfiltered = silenceRun(0.0);
    Silence::watch($untimed, ['T::a'], '/tmp/mutations/timed', '--filter="T::a"', 'P\\Plus');
    Silence::watch($untimed, ['T::never'], '/tmp/mutations/untimed', '--filter="T::never"', 'P\\Plus');
    Silence::watch($unfiltered, ['T::a'], '/tmp/mutations/unfiltered', str_repeat('T::a|', Ceiling::BYTES), 'P\\Plus');
    $runs = [$unwatched, $untimed, $unfiltered];
    array_map(silenceBeaten(...), $runs);
    $now = microtime(as_float: true);

    foreach ([$now, $now + 1000.0] as $at) {
        array_map(static fn(Process $run) => Silence::check($run, $at), $runs);
    }

    expect(array_map(static fn(Process $run): bool => $run->isRunning(), $runs))->toBe([true, true, true]);

    foreach ($runs as $run) {
        $run->stop(0);
    }
});
