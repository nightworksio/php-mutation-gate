<?php

declare(strict_types=1);

namespace Library;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Marked
{
}
