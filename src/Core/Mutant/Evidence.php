<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a runner says of how it killed a mutant, beside the tests that did:
 * how far its own run went (Prefix), and, for a kill no test is named for,
 * how its process ended (Ended), by which that kill stands or is unjudged
 * (see Unevidenced). A runner that cannot tell either gives none of it.
 */
final readonly class Evidence
{
    private function __construct(private Prefix|NotGiven $prefix, private Ended|NotGiven $ended)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value());
    }

    public function withPrefix(Prefix $prefix): self
    {
        return new self($prefix, $this->ended);
    }

    public function withEnded(Ended $ended): self
    {
        return new self($this->prefix, $ended);
    }

    public function prefix(): Prefix|NotGiven
    {
        return $this->prefix;
    }

    public function ended(): Ended|NotGiven
    {
        return $this->ended;
    }

    /** Whether it says nothing. */
    public function isEmpty(): bool
    {
        return $this->prefix instanceof NotGiven && $this->ended instanceof NotGiven;
    }
}
