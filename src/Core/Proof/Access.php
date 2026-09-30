<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Ci\Detached;

use function sprintf;

/**
 * Which ledgers a run reads and which it writes. It reads its own scope and
 * the default branch's, and writes only its own, so the default branch's
 * scope is written only by runs on that branch: a pull request cannot plant a
 * proof the default branch will trust. A run on a detached `HEAD` has no
 * scope: it reads the default branch's and writes none.
 */
final readonly class Access
{
    private function __construct(private Scope|Detached $own, private Scope $defaultBranch, private Writing $writing)
    {
    }

    public static function of(Scope|Detached $own, Scope $defaultBranch, Writing $writing): self
    {
        return new self($own, $defaultBranch, $writing);
    }

    /** Its own scope first, then the default branch's; one scope where the run is on the default branch or has none. */
    public function reads(): Scopes
    {
        return $this->own instanceof Scope
            ? Scopes::of($this->own, $this->defaultBranch)
            : Scopes::of($this->defaultBranch);
    }

    public function writes(): Scope|ReadsOnly
    {
        if ($this->own instanceof Detached) {
            return ReadsOnly::because(sprintf(
                'The run has no ref of its own, so it reads the ledger of %s and writes none.',
                $this->defaultBranch->ref(),
            ));
        }

        return match ($this->writing) {
            Writing::Auto => $this->own,
            Writing::Never => ReadsOnly::because(sprintf(
                'proofs.write is never, so the ledger of %s is read and not written.',
                $this->own->ref(),
            )),
        };
    }
}
