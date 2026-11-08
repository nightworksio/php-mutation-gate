<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a runner says of how it killed a mutant, beside the tests that did:
 * how far its own run went (Prefix), and, for a kill no test is named for,
 * how its process ended (Ended), by which that kill stands or is unjudged
 * (see Unevidenced), and whether an unmutated control of its tests passed,
 * which vouches for that kill (see UnnamedKillControls). Of any mutant: the
 * test files its own run loaded with no filter, where the runner left the
 * filter of its tests out, which its controls run the same way (see
 * Control). A runner that cannot tell any of it gives none of it.
 */
final readonly class Evidence
{
    private function __construct(
        private Prefix|NotGiven $prefix,
        private Ended|NotGiven $ended,
        private bool $vouched,
        private Paths|WholeSuite|NotGiven $unfiltered = new NotGiven(),
        private bool $streamed = false,
    ) {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), vouched: false);
    }

    public function withPrefix(Prefix $prefix): self
    {
        return new self($prefix, $this->ended, $this->vouched, $this->unfiltered, $this->streamed);
    }

    public function withEnded(Ended $ended): self
    {
        return new self($this->prefix, $ended, $this->vouched, $this->unfiltered, $this->streamed);
    }

    /**
     * This evidence, of a mutant a split stream judged against its own
     * unmutated stream, whose verdict needs no control (ADR-0117).
     */
    public function streamed(): self
    {
        return new self($this->prefix, $this->ended, $this->vouched, $this->unfiltered, streamed: true);
    }

    /** Whether a split stream judged the mutant, against its own unmutated stream (ADR-0117). */
    public function isStreamed(): bool
    {
        return $this->streamed;
    }

    /** This evidence, with an unmutated control of the mutant's tests that passed. */
    public function vouched(): self
    {
        return new self($this->prefix, $this->ended, vouched: true, unfiltered: $this->unfiltered, streamed: $this->streamed);
    }

    /**
     * This evidence, with the test files the mutant's own run loaded with no
     * filter: these, or every one.
     */
    public function loadingUnfiltered(Paths|WholeSuite $files): self
    {
        return new self($this->prefix, $this->ended, $this->vouched, $files, $this->streamed);
    }

    /** The test files the mutant's own run loaded with no filter, or every one; not given where it ran a filter. */
    public function unfiltered(): Paths|WholeSuite|NotGiven
    {
        return $this->unfiltered;
    }

    /** Whether an unmutated control of the mutant's tests passed. */
    public function isVouched(): bool
    {
        return $this->vouched;
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
        return $this->prefix instanceof NotGiven
            && $this->ended instanceof NotGiven
            && ! $this->vouched
            && $this->unfiltered instanceof NotGiven
            && ! $this->streamed;
    }
}
