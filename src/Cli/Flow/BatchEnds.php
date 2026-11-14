<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Undigested;

/**
 * What is told of each batch of a run as it ends (ADR-0120): `watch` prints
 * what each judged, where `local.streamBatches` says so, rather than only
 * the verdict once the round is over, with the digests of the files the plan
 * judged (ADR-0146). A batch the runner could not judge tells nothing; what
 * it says is in the verdict.
 *
 * @internal
 */
final readonly class BatchEnds
{
    /** @param list<Closure(Invoked, Digests|Undigested): void> $told each told of a batch as it ends */
    public function __construct(private array $told = [], private Digests|Undigested $digests = new Undigested())
    {
    }

    /** @param Closure(Invoked, Digests|Undigested): void $told */
    public static function telling(Closure $told): self
    {
        return new self([$told]);
    }

    /** The same, telling of the files by these digests the plan took. */
    public function digested(Digests|Undigested $digests): self
    {
        return new self($this->told, $digests);
    }

    /** What a batch came to, told to each, where the runner judged it. */
    public function ended(Invoked|CannotJudge $invoked): void
    {
        if (! $invoked instanceof Invoked) {
            return;
        }

        foreach ($this->told as $told) {
            $told($invoked, $this->digests);
        }
    }
}
