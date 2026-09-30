<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

/** A path that no tree holds. */
final readonly class Outside
{
    public static function trees(): self
    {
        return new self();
    }
}
