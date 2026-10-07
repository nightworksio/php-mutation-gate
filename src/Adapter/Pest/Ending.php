<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_replace;

use Symfony\Component\Process\Process;

/**
 * How a mutant's own process ended, as Pest's parent process saw it once
 * the process failed, which is how Pest tells a mutant tested (ADR-0014,
 * decision 16): the code it exited with, whether a signal ended it, and
 * what it printed on its output and then on its error output, its beats
 * (see Silence) left out, as much of it as evidence keeps (see Ended),
 * recorded by the mutant's mutated copy where the gate names a results
 * file. It records nothing the parent process did not already hold.
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

        $code = $process->getExitCode();
        $printed = sprintf('%s%s', $process->getOutput(), str_replace(Silence::BEAT, '', $process->getErrorOutput()));
        $ended = Ended::of($code ?? NotGiven::value(), $process->hasBeenSignaled(), $printed);
        file_put_contents($results, RecordLine::ended($mutated, $ended), FILE_APPEND | LOCK_EX);
    }
}
