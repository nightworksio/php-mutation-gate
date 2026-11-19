<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function sprintf;

/**
 * The time a machine's cores have spent since it started, as its system
 * counts it in ticks: busy, and in all, across its cores, with how many
 * cores it has. Two readings say how many cores were busy, on average,
 * between them.
 *
 * @internal
 */
final readonly class CoreTimes
{
    private const string BUSY = 'cores %.1f of %d busy';

    private function __construct(private int $busy, private int $total, private int $cores)
    {
    }

    public static function of(int $busy, int $total, int $cores): self
    {
        return new self($busy, $total, $cores);
    }

    /** How many cores were busy on average since that earlier reading; none where no tick has passed. */
    public function busySince(self $earlier): string
    {
        $ticks = $this->total - $earlier->total;

        return $ticks > 0 && $this->cores > 0
            ? sprintf(self::BUSY, max(0, $this->busy - $earlier->busy) / $ticks * $this->cores, $this->cores)
            : '';
    }
}
