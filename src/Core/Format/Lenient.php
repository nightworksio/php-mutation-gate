<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/**
 * Reading a file whose shape nothing promises, such as one Composer wrote: a
 * place that holds something other than what is asked for reads as holding
 * none of it, so a reader skips it rather than refusing the file.
 */
final readonly class Lenient
{
    /** JSON's word for a value that is not there. */
    private const string NOTHING = 'null';

    /** The text a place holds; none where it holds something else, or nothing. */
    public static function text(Node $place): string
    {
        try {
            return $place->text();
        } catch (NotInShape) {
            return '';
        }
    }

    /** True or false where the place holds it; otherwise what a reader takes when it holds neither. */
    public static function boolean(Node $place, bool $otherwise): bool
    {
        try {
            return $place->boolean();
        } catch (NotInShape) {
            return $otherwise;
        }
    }

    /** The whole number a place holds; 0 where it holds something else, or nothing. */
    public static function integer(Node $place): int
    {
        try {
            return $place->integer();
        } catch (NotInShape) {
            return 0;
        }
    }

    /** What a place holds, written back out as JSON; `null` where it holds nothing. */
    public static function json(Node $place): string
    {
        try {
            return $place->json();
        } catch (NotInShape) {
            return self::NOTHING;
        }
    }

    /** Whether a place holds a map or a list, rather than a single value or nothing. */
    public static function holdsMembers(Node $place): bool
    {
        try {
            $place->entries();

            return true;
        } catch (NotInShape) {
            return false;
        }
    }

    /**
     * The items of a list; none where the place holds no list.
     *
     * @return list<Node>
     */
    public static function items(Node $place): array
    {
        try {
            return $place->items();
        } catch (NotInShape) {
            return [];
        }
    }

    /**
     * The members of a map or a list, by key, a list's by its index; none
     * where the place holds neither.
     *
     * @return array<array-key, Node>
     */
    public static function entries(Node $place): array
    {
        try {
            return $place->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
