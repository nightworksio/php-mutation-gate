<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function sprintf;

/**
 * Which ledgers a run reads and which it writes. It reads its own scope and
 * the default branch's, and writes only its own, so the default branch's
 * scope is written only by runs on that branch: a pull request cannot plant a
 * proof the default branch will trust.
 */
final readonly class Access
{
    private function __construct(private Scope $own, private Scope $defaultBranch, private Writing $writing)
    {
    }

    public static function of(Scope $own, Scope $defaultBranch, Writing $writing): self
    {
        return new self($own, $defaultBranch, $writing);
    }

    /** Its own scope first, then the default branch's; one scope where the run is on the default branch. */
    public function reads(): Scopes
    {
        return Scopes::of($this->own, $this->defaultBranch);
    }

    public function writes(): Scope|ReadsOnly
    {
        return match ($this->writing) {
            Writing::Auto => $this->own,
            Writing::Never => ReadsOnly::because(sprintf(
                'proofs.write is never, so the ledger of %s is read and not written.',
                $this->own->ref(),
            )),
        };
    }
}
