<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Process\Process;

/**
 * How a mutant's own process ended, as Pest's parent process saw it once
 * the process failed, which is how Pest tells a mutant tested (ADR-0014,
 * decision 16): the code it exited with and whether a signal ended it,
 * recorded by the mutant's mutated copy where the gate names a results
 * file. What it printed is never recorded: this process does not hold the
 * values the gate withholds, so it cannot screen it for them, and the
 * results file sits in the workspace a CI may upload.
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

        $diag = getenv('DIAG_OWN_RUNS');
        if (is_string($diag) && $diag !== '') {
            file_put_contents($diag, sprintf("=== %s exit %s\n%s\n%s\n", $mutated, (string) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()), FILE_APPEND);
        }

        $ended = Ended::unprinted($process->getExitCode() ?? NotGiven::value(), $process->hasBeenSignaled());
        file_put_contents($results, RecordLine::ended($mutated, $ended), FILE_APPEND | LOCK_EX);
    }
}
