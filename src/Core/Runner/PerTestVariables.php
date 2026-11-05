<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_string;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function sprintf;

/**
 * How a process of a patched runner is told the limit each test of a
 * mutant's run is held to (ADR-0090): its multiple and its floor, each in a
 * variable of its own.
 *
 * @internal
 */
final readonly class PerTestVariables
{
    /**
     * What a process is told of this limit, by the variables it reads;
     * nothing where it is off.
     *
     * @return array<string, string>
     */
    public static function of(PerTestLimit $limit): array
    {
        return $limit->isOn() ? [
            ChildVariable::TestMultiple->value => sprintf('%d', $limit->multiple()),
            ChildVariable::TestFloor->value => sprintf('%F', $limit->floor()->seconds()),
        ] : [];
    }

    /**
     * What a process was told, as it reads the variables: no limit where it
     * was told nothing, a multiple that is no positive whole number, or a
     * floor that is no positive number of seconds.
     */
    public static function read(string|false $multiple, string|false $floor): PerTestLimit
    {
        $seconds = ToldSeconds::read($floor);

        return is_string($multiple) && WholeNumber::isDigits($multiple) && $seconds instanceof Seconds
            ? PerTestLimit::of((int) $multiple, $seconds)
            : PerTestLimit::off();
    }
}
