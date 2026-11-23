<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Time\Share;

/**
 * How a runner looks for the tests that kill each mutant: the order it runs
 * the mutant's covering tests in (ADR-0013), and whether it stops
 * at the first that fails or runs every one and records each that fails, a
 * full kill matrix (ADR-0014). The order decides which test is
 * found first, so it matters only where the run stops there. A local run
 * also runs each mutant another subsumes after the others (ADR-0079). It says
 * too what a kill's unmutated control runs (ADR-0108).
 */
final readonly class KillSearch
{
    private function __construct(
        private Ordering $ordering,
        private MatrixKind $matrix,
        private Subsumption|NotGiven $held,
        private KillControl $killControls,
        private Share $confirmShare,
    ) {
    }

    /** The runner's own order, stopping at the first killer. */
    public static function standard(): self
    {
        return self::of(Ordering::runner(), MatrixKind::FirstKiller);
    }

    public static function of(Ordering $ordering, MatrixKind $matrix): self
    {
        return new self($ordering, $matrix, NotGiven::value(), KillControl::Order, Share::whole());
    }

    /** This search, running each mutant another the run offers subsumes after the others (ADR-0079). */
    public function holdingBack(Subsumption $held): self
    {
        return new self($this->ordering, $this->matrix, $held, $this->killControls, $this->confirmShare);
    }

    /** This search, whose kills are controlled so (ADR-0108). */
    public function controllingKills(KillControl $killControls): self
    {
        return new self($this->ordering, $this->matrix, $this->held, $killControls, $this->confirmShare);
    }

    /**
     * This search, whose run again of doubtful kills with every test file takes at most this share of the time
     * the run has left as it starts (ADR-0332).
     */
    public function confirmingWithin(Share $share): self
    {
        return new self($this->ordering, $this->matrix, $this->held, $this->killControls, $share);
    }

    /** The share of the time left the run again of doubtful kills may take: all of it where none is set. */
    public function confirmShare(): Share
    {
        return $this->confirmShare;
    }

    /** What a kill's unmutated control runs. */
    public function killControls(): KillControl
    {
        return $this->killControls;
    }

    public function ordering(): Ordering
    {
        return $this->ordering;
    }

    /** Whether each mutant stops at its first killer, or records every one. */
    public function matrix(): MatrixKind
    {
        return $this->matrix;
    }

    /** Which mutant subsumes which, by which a run holds the subsumed back; nothing where it holds none back. */
    public function held(): Subsumption|NotGiven
    {
        return $this->held;
    }
}
