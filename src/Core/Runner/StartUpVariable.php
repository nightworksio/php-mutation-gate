<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * How a process of a patched runner is told the start-up a run measured,
 * which it lays each mutant's limit on (ADR-0008).
 *
 * @internal
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
        $startUp = $bounds->startUp()->measured();
        $unmeasured = $bounds->startUp()->unmeasured()->seconds();

        return [
            ...$startUp instanceof Seconds
                ? [ChildVariable::MutantStartUp->value => sprintf('%F', $startUp->seconds())]
                : [],
            ...$unmeasured === MutantLimit::START_UP
                ? []
                : [ChildVariable::UnmeasuredStartUp->value => sprintf('%F', $unmeasured)],
        ];
    }

    /** What a process was told a run gets to start in unmeasured, `timeouts.startUp`; or the standard. */
    public static function unmeasured(string|false $told): float
    {
        $seconds = ToldSeconds::read($told);

        return $seconds instanceof Seconds ? $seconds->seconds() : MutantLimit::START_UP;
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
