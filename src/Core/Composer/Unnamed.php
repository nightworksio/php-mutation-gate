<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

/** A manifest that declares no package name, as a project's root one may. */
final readonly class Unnamed
{
    public static function package(): self
    {
        return new self();
    }
}
