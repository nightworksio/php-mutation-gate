<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function is_file;
use function pathinfo;

/**
 * The switch a schema's sites read (ADR-0091): for each file, by its key,
 * the id of the one mutant of it in force in this process, which the runner
 * turns on as the process starts its tests; none, where every site of the
 * file runs the original. It loads none of the gate's classes but
 * ServedClasses, as it runs in a mutant's own run before the mutant is in
 * place.
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

    /**
     * The file a mutant's own run serves in its file's place, its mutant put
     * in force: the schema it was told, where it was told one that is there
     * and its switch's id, and its own copy otherwise; nothing, where the
     * process already serves the schema, as a child of a mutants' warm
     * worker does. The schema is named by its file's key.
     */
    public static function inForce(string $original, string $copy, string $schema, string $on): string
    {
        $id = (int) $on;

        if ($id < 1 || (string) $id !== $on || ! is_file($schema)) {
            return $copy;
        }

        self::on(pathinfo($schema, PATHINFO_FILENAME), $id);

        return ServedClasses::serves($original, $schema) ? '' : $schema;
    }
}
