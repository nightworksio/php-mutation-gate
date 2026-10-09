<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * How a process of a patched runner is told the start-up a run measured,
 * which it lays each mutant's limit on (ADR-0008, decision 2).
 */
final readonly class StartUpVariable
{
    /**
     * What a process is told of the start-up these bounds measured, by the
     * variable it reads; nothing where none was measured.
     *
     * @return array<string, string>
     */
    public static function of(LimitBounds $bounds): array
    {
        $startUp = $bounds->startUp();

        return $startUp instanceof Seconds
            ? [ChildVariable::MutantStartUp->value => sprintf('%F', $startUp->seconds())]
            : [];
    }

    /**
     * What a process was told, as it reads the variable: unmeasured where it
     * was told nothing, or no positive number of seconds.
     */
    public static function read(string|false $startUp): Seconds|Unmeasured
    {
        $seconds = ToldSeconds::read($startUp);

        return $seconds instanceof Seconds ? $seconds : Unmeasured::duration();
    }
}
