<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

/** A key of Infection's config the gate does not know. */
final readonly class Unknown
{
    private function __construct()
    {
    }

    public static function key(): self
    {
        return new self();
    }
}
