<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Proofs;

/**
 * What GitHub's word proves of a range of the default branch (ADR-0139): whether every commit of it is proved; the
 * judged head the change is read from where a pull request's landed tree differs from it under `delta`, the oldest
 * such head first, none where every tree is the one judged; and the results the trusted passes carry.
 *
 * @internal
 */
final readonly class MergedRange
{
    private function __construct(private bool $proved, private Revision|NotGiven $from, private Proofs $carried)
    {
    }

    public static function unproved(): self
    {
        return new self(proved: false, from: NotGiven::value(), carried: Proofs::none());
    }

    /** A range every commit of which is proved, judged on its own tree, carrying nothing yet. */
    public static function proved(): self
    {
        return new self(proved: true, from: NotGiven::value(), carried: Proofs::none());
    }

    /** This range, also carrying these results, and read from this judged head where it is the first that differs. */
    public function and(Proofs $carried, Revision|NotGiven $judged): self
    {
        return new self(
            $this->proved,
            $this->from instanceof Revision ? $this->from : $judged,
            Proofs::of(...$this->carried, ...$carried),
        );
    }

    public function isProved(): bool
    {
        return $this->proved;
    }

    /** The judged head the change is read from, where a landed tree differs from the one judged. */
    public function from(): Revision|NotGiven
    {
        return $this->from;
    }

    /** The results the trusted passes carry; none where the range is not proved. */
    public function carried(): Proofs
    {
        return $this->proved ? $this->carried : Proofs::none();
    }
}
