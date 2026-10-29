<?php

declare(strict_types=1);

namespace Library;

/** A screen a container makes by reflection, closed until it is opened. */
final class Screen
{
    public bool $open = false;

    public function __construct(#[Marked] public readonly string $title)
    {
    }
}
