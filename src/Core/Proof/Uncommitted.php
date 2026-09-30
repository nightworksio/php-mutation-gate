<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * Digests taken from a working tree that held what its commit does not, or
 * whose commit git could not name: they stand for no commit, so what changed
 * since they were taken cannot be read from version control.
 */
final readonly class Uncommitted
{
    public static function tree(): self
    {
        return new self();
    }
}
