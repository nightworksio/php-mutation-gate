<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A setting the config leaves out, where leaving it out has a meaning of its own. */
final readonly class Absent
{
    public static function setting(): self
    {
        return new self();
    }

    /**
     * What a later layer sets, or what an earlier one set where the later leaves it out.
     *
     * @template E of bool|float|int|object|string
     * @template L of bool|float|int|object|string
     *
     * @param  E|self   $earlier
     * @param  L|self   $later
     * @return E|L|self
     */
    public static function laid(
        bool|float|int|object|string $earlier,
        bool|float|int|object|string $later,
    ): bool|float|int|object|string {
        return $later instanceof self ? $earlier : $later;
    }
}
