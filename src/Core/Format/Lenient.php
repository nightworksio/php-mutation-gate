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
