<?php

declare(strict_types=1);

namespace Library;

/** How a basket is counted: many from three items. */
final readonly class Split
{
    public function count(int $items): string
    {
        return $items >= 3 ? 'many' : 'few';
    }
}
