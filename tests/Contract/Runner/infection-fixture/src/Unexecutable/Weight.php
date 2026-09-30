<?php

declare(strict_types=1);

namespace Library\Unexecutable;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Weight
{
    public function __construct(public int $kilos)
    {
    }
}
