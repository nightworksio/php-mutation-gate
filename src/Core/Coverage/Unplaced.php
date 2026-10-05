<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/**
 * A coverage map that does not say where it was measured: the lines of some
 * files alone, as a shard or the verdict is handed them, one where git could
 * not tell the commit, or one written before maps recorded it.
 */
final readonly class Unplaced
{
    private function __construct()
    {
    }

    public static function map(): self
    {
        return new self();
    }
}
