<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/**
 * A run with no ref of its own, on a detached `HEAD`. It has no scope: it
 * reads the default branch's ledger and writes none.
 */
final readonly class Detached
{
    public static function head(): self
    {
        return new self();
    }
}
