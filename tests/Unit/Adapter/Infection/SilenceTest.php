<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Silence;
use NightWorksIO\MutationGate\Adapter\Infection\Silenced;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InfectionMutant;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    putenv(sprintf('%s=1', ChildVariable::MutantFloor->value));
});

afterEach(function (): void {
    putenv(ChildVariable::MutantFloor->value);
    putenv(ChildVariable::Results->value);
    Scratch::sweep();
});

/**
 * A mutant's run that prints this on its output, as a test framework prints
 * its header, and then a dot on its error output every tenth of a second for
 * half a minute, which is no progress.
 */
function infectionRun(string $header): Process
{
    $process = new Process([PHP_BINARY, '-r', sprintf(
        'echo %s; for ($i = 0; $i < 300; $i++) { fwrite(STDERR, "."); usleep(100000); }',
        var_export($header, return: true),
    )]);
    $process->start();

    return $process;
}

/** Waits until the run has printed on its error output, so what it printed before is in. */
function infectionStarted(Process $process): void
{
    $process->waitUntil(static fn(): bool => $process->getErrorOutput() !== '');
}

it('stops a run once it has printed nothing for its silence limit since it last printed, recording the mutant', function (): void {
    $results = sprintf('%s/silenced.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', ChildVariable::Results->value, $results));
    $mutant = InfectionMutant::of([InfectionMutant::test('T::a', 1.0)]);
    $process = infectionRun('PHPUnit');
    Silence::watch($process, $mutant, 300.0);
    infectionStarted($process);
    $start = microtime(as_float: true);

    Silence::check($process, $start);
    Silence::check($process, $start + 7.9);

    expect($process->isRunning())->toBeTrue()
        ->and(static fn() => Silence::check($process, $start + 8.1))->toThrow(ProcessTimedOutException::class)
        ->and($process->isRunning())->toBeFalse()
        ->and(Silenced::in($results)->of('/p/src/Money.php', 11, 'Plus', "--- a\n+++ b"))->toEqual(Seconds::of(8.0));
});

it('never stops a run before it first prints, nor one it does not watch, nor one watched again under an untimed test', function (): void {
    $timed = InfectionMutant::of([InfectionMutant::test('T::a', 1.0)]);
    $quiet = infectionRun('');
    $unwatched = infectionRun('PHPUnit');
    $untimed = infectionRun('PHPUnit');
    Silence::watch($quiet, $timed, 300.0);
    Silence::watch($untimed, $timed, 300.0);
    Silence::watch($untimed, InfectionMutant::of([InfectionMutant::untimed('T::a')]), 300.0);
    $runs = [$quiet, $unwatched, $untimed];
    array_map(infectionStarted(...), $runs);
    $now = microtime(as_float: true);

    foreach ([$now, $now + 1000.0] as $at) {
        array_map(static fn(Process $run) => Silence::check($run, $at), $runs);
    }

    expect(array_map(static fn(Process $run): bool => $run->isRunning(), $runs))->toBe([true, true, true]);

    foreach ($runs as $run) {
        $run->stop(0);
    }
});
