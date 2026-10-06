<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/** A CI definition, or a line of one, whose part in how its runner reads it cannot be told. */
final readonly class Unread
{
    private function __construct()
    {
    }

    public static function line(): self
    {
        return new self();
    }
}
