<?php

declare(strict_types=1);

namespace Library\Unexecutable;

use Attribute;

#[Attribute]
final readonly class Weight
{
    public function __construct(public int $kilos)
    {
    }
}
