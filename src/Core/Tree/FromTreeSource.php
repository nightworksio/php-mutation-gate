<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

/** Trees the config does not declare, which the tree source answers instead. */
final readonly class FromTreeSource
{
    private function __construct()
    {
    }

    public static function trees(): self
    {
        return new self();
    }
}
