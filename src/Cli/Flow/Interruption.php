<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;

use Closure;

/**
 * What stops a budgeted run before its next batch, besides its deadline: a
 * change `watch` sees arrive mid-run (ADR-0010, decision 1). The batches
 * already run keep their results; the units no batch took are left
 * unjudged, as when the time runs out. A run with none is stopped only by
 * its deadline.
 */
final readonly class Interruption
{
    /** @param list<Closure(): bool> $arrivals each saying whether what stops the run has arrived */
    public function __construct(private array $arrivals = [])
    {
    }

    /** Stopped once this says what stops the run has arrived. */
    public static function when(Closure $arrived): self
    {
        return new self([$arrived]);
    }

    /** Whether what stops the run has arrived. */
    public function arrived(): bool
    {
        return array_any($this->arrivals, static fn(Closure $arrival): bool => $arrival() === true);
    }
}
