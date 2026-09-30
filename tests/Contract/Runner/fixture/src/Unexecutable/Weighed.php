<?php

declare(strict_types=1);

namespace Library\Unexecutable;

use ReflectionClass;

/** An attribute's argument, which no reference names, read by reflection in a covered method. */
#[Weight(5)]
final class Weighed
{
    public function weight(): int
    {
        return new ReflectionClass(self::class)->getAttributes(Weight::class)[0]->newInstance()->kilos;
    }
}
