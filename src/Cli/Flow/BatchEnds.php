<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;

/**
 * What is told of each batch of a run as it ends (ADR-0120): `watch` prints
 * what each judged, where `local.streamBatches` says so, rather than only
 * the verdict once the round is over. A batch the runner could not judge
 * tells nothing; what it says is in the verdict.
 *
 * @internal
 */
final readonly class BatchEnds
{
    /** @param list<Closure(Invoked): void> $told each told of a batch as it ends */
    public function __construct(private array $told = [])
    {
    }

    /** @param Closure(Invoked): void $told */
    public static function telling(Closure $told): self
    {
        return new self([$told]);
    }

    /** What a batch came to, told to each, where the runner judged it. */
    public function ended(Invoked|CannotJudge $invoked): void
    {
        if (! $invoked instanceof Invoked) {
            return;
        }

        foreach ($this->told as $told) {
            $told($invoked);
        }
    }
}
