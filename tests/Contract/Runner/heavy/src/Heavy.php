<?php

declare(strict_types=1);

namespace Library;

/** How a parcel is weighed: heavy past a kilogram. */
final readonly class Heavy
{
    public function weigh(int $grams): string
    {
        return $grams > 1000 ? 'heavy' : 'light';
    }
}
