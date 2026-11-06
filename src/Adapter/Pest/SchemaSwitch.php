<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

/**
 * The switch a schema's sites read (ADR-0091): for each file, by its key,
 * the id of the one mutant of it in force in this process, which the runner
 * turns on as the process starts its tests; none, where every site of the
 * file runs the original.
 *
 * @internal
 */
final class SchemaSwitch
{
    /** @var array<string, int> the id of the mutant in force, by the key of the file whose schema switches it */
    private static array $on = [];

    /** Puts the mutant this id names in force in the file this key names. */
    public static function on(string $file, int $id): void
    {
        self::$on[$file] = $id;
    }

    /** Puts no mutant of the file this key names in force. */
    public static function off(string $file): void
    {
        unset(self::$on[$file]);
    }

    /** Whether the mutant this id names is the one in force in the file this key names. */
    public static function is(string $file, int $id): bool
    {
        return array_key_exists($file, self::$on) && self::$on[$file] === $id;
    }
}
