<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/** Code that has no name to point at it by: a line in no function, or a change that calls nothing. */
final readonly class Nameless
{
    private function __construct()
    {
    }

    public static function code(): self
    {
        return new self();
    }
}
