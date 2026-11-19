<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use Closure;

/**
 * What GitHub said of each range a run asked about, by its base, so the plan's change, the ledgers it reads and
 * the verdict's ask it once.
 *
 * @internal
 */
final class MergedAnswers
{
    /** @var array<string, MergedRange> by the base the range starts after */
    private array $ranges = [];

    public static function none(): self
    {
        return new self();
    }

    /** @param Closure(): MergedRange $asked */
    public function of(string $base, Closure $asked): MergedRange
    {
        return $this->ranges[$base] ??= $asked();
    }
}
