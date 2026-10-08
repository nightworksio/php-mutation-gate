<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_put_contents;
use function getenv;
use function getmypid;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Process\Process;

/**
 * How a mutant's own process ended, as Pest's parent process saw it once
 * the process failed, which is how Pest tells a mutant tested (ADR-0014,
 * decision 16): the code it exited with and whether a signal ended it,
 * recorded by the mutant's mutated copy where the gate names a results
 * file; and, where collecting its tests failed on a test's missing dataset,
 * that test as its killer (see CollectionKiller). What it printed is never
 * recorded: this process does not hold the values the gate withholds, so it
 * cannot screen it for them, and the results file sits in the workspace a
 * CI may upload. A killer it names is the id of a test this process
 * collected, not text the run printed.
 */
final readonly class Ending
{
    /** Records how this own run, on this mutated copy, ended. */
    public static function record(Process $process, string $mutated): void
    {
        $results = getenv(GateVariable::Results->value);

        if (! is_string($results) || $results === '') {
            return;
        }

        $ended = Ended::unprinted($process->getExitCode() ?? NotGiven::value(), $process->hasBeenSignaled());
        $killer = CollectionKiller::in($process->getOutput());
        $killed = is_string($killer) ? RecordLine::killed($mutated, $killer, Placed::unplaced((int) getmypid())) : '';
        $lines = sprintf('%s%s', RecordLine::ended($mutated, $ended), $killed);
        file_put_contents($results, $lines, FILE_APPEND | LOCK_EX);
    }
}
