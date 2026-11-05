<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/**
 * The switch a schema's sites read (ADR-0091): the id of the one mutant in
 * force in this process, which the runner turns on as the process starts
 * its tests, or none, where every site runs the original.
 *
 * @internal
 */
final class SchemaSwitch
{
    /** The id no mutant has: every site runs the original. */
    private const int NONE = 0;

    private static int $on = self::NONE;

    /** Puts the mutant this id names in force. */
    public static function on(int $id): void
    {
        self::$on = $id;
    }

    /** Puts no mutant in force. */
    public static function off(): void
    {
        self::$on = self::NONE;
    }

    /** Whether the mutant this id names is the one in force. */
    public static function is(int $id): bool
    {
        return self::$on === $id;
    }
}
