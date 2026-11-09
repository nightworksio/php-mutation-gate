<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use Closure;

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

    /**
     * What a split stream decides at a site of a file no mutant is in force
     * in: whether this execution runs the mutant (ADR-0117); none outside a
     * split stream, where every such site runs its original.
     *
     * @var (Closure(string, int): bool)|null
     */
    private static ?Closure $split = null;

    /**
     * What a split stream gives a pure expression's site whose mutant is not
     * in force, given its original's value and its mutated code: the value
     * this execution runs with (ADR-0117).
     *
     * @var (Closure(string, int, mixed, Closure(): mixed): mixed)|null
     */
    private static ?Closure $changed = null;

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
        if (array_key_exists($file, self::$on)) {
            return self::$on[$file] === $id;
        }

        return self::$split instanceof Closure && (self::$split)($file, $id);
    }

    /**
     * The value of a pure expression's site (ADR-0117): its mutated code's,
     * where its mutant is in force, and its original's otherwise; in a split
     * stream, the stream sees both first, and forks where they differ.
     *
     * @param Closure(): mixed $original
     * @param Closure(): mixed $mutated
     */
    public static function value(string $file, int $id, Closure $original, Closure $mutated): mixed
    {
        if (array_key_exists($file, self::$on)) {
            return self::$on[$file] === $id ? $mutated() : $original();
        }

        $value = $original();

        return self::$changed instanceof Closure ? (self::$changed)($file, $id, $value, $mutated) : $value;
    }

    /**
     * Has a split stream decide each site of a file no mutant is in force in
     * (ADR-0117).
     *
     * @param Closure(string, int): bool                     $split   at a site its switch decides
     * @param Closure(string, int, mixed, Closure(): mixed): mixed $changed at a pure expression's site
     */
    public static function splitting(Closure $split, Closure $changed): void
    {
        self::$split = $split;
        self::$changed = $changed;
    }

    /** Has no split stream decide any site. */
    public static function unsplit(): void
    {
        self::$split = null;
        self::$changed = null;
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
