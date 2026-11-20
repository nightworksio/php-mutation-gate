<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use Closure;
use NightWorksIO\MutationGate\Core\Proof\ReusablePass;

/**
 * What GitHub said of each range a run asked about, by its base, and of the pass a run may reuse, so the plan's
 * change, the ledgers it reads and the verdict's ask it once.
 *
 * @internal
 */
final class MergedAnswers
{
    /** @var array<string, MergedRange> by the base the range starts after */
    private array $ranges = [];

    /** @var array<'', list<ReusablePass>> the pass a run may reuse, once asked */
    private array $reusable = [];

    public static function none(): self
    {
        return new self();
    }

    /** @param Closure(): MergedRange $asked */
    public function of(string $base, Closure $asked): MergedRange
    {
        return $this->ranges[$base] ??= $asked();
    }

    /**
     * @param  Closure(): list<ReusablePass> $asked
     * @return list<ReusablePass>
     */
    public function reusable(Closure $asked): array
    {
        return $this->reusable[''] ??= $asked();
    }
}
