<?php

declare(strict_types=1);

namespace Library;

/**
 * A loop on true that ends at once: WhileAlwaysFalse and TrueToFalse both
 * leave it `while (false)`, and RemoveArrayItem leaves `['x']` whichever item
 * it removes, so two pairs of mutants leave the file alike. Each returns what
 * the spec refuses, so a failed assertion kills each.
 */
final readonly class Looped
{
    /** @return list<string> */
    public function twice(): array
    {
        $items = [];

        while (true) {
            $items = ['x', 'x'];

            break;
        }

        return $items;
    }
}
