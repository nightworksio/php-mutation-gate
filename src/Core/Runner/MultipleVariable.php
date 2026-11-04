<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_numeric;
use function sprintf;

/**
 * How a process of a patched runner is told the multiple of its covering
 * tests' own time each mutant is allowed, `timeouts.multiple`, written or
 * learned (ADR-0008).
 *
 * @internal
 */
final readonly class MultipleVariable
{
    /**
     * What a process is told of the multiple these bounds allow, by the
     * variable it reads; nothing where they allow the standard, which it
     * reads where it is told nothing.
     *
     * @return array<string, string>
     */
    public static function of(LimitBounds $bounds): array
    {
        $multiple = $bounds->multiple();

        return $multiple->isStandard()
            ? []
            : [ChildVariable::MutantMultiple->value => sprintf('%F', $multiple->times())];
    }

    /** What a process was told, as it reads the variable: the standard where it was told no positive number. */
    public static function read(string|false $multiple): Multiple
    {
        return is_numeric($multiple) && (float) $multiple > 0.0
            ? Multiple::of((float) $multiple)
            : Multiple::standard();
    }
}
