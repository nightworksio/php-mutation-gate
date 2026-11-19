<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function max;

/**
 * `splitStream.partsPerProcess`: how many parts a pass of split streams cuts
 * its covering test files into for each stream it runs at once, at least one
 * (ADR-0188).
 */
final readonly class PartsPerProcess
{
    private function __construct(private int $count)
    {
    }

    /** This many parts for each process, one where fewer are given. */
    public static function of(int $count): self
    {
        return new self(max(1, $count));
    }

    /** How many parts for each process. */
    public function count(): int
    {
        return $this->count;
    }
}
