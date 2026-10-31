<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function max;

use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Report\NoTrend;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;

/**
 * The account of a run beside its verdict: when each part began and how
 * long it took, what it cost, what it saved, the runs `trend.json` held
 * from before it (ADR-0016, and ADR-0017),
 * what pruning did (ADR-0025), and where the verdict comes from (ADR-0009).
 * A run the flows gave no timings says nothing of its cost.
 */
final readonly class RunAccount
{
    private function __construct(
        private RunTimings|Untimed $timings,
        private Cost|Untimed $cost,
        private Savings|NoHistory $savings,
        private Trend|NoTrend $previous,
        private PruningAccount $pruning,
        private Provenance $provenance,
    ) {
    }

    /** No timings, no cost, no history, and no trend, as off the default branch. */
    public static function none(): self
    {
        return new self(
            Untimed::run(),
            Untimed::run(),
            NoHistory::yet(),
            NoTrend::offTheDefaultBranch(),
            PruningAccount::none(),
            Provenance::unknown(),
        );
    }

    /** This account, with where the verdict comes from. */
    public function withProvenance(Provenance $provenance): self
    {
        return new self($this->timings, $this->cost, $this->savings, $this->previous, $this->pruning, $provenance);
    }

    /** Where the verdict comes from: the gate, the commit, the runner and the baseline file. */
    public function provenance(): Provenance
    {
        return $this->provenance;
    }

    public function withTimings(RunTimings $timings): self
    {
        return new self($timings, $this->cost, $this->savings, $this->previous, $this->pruning, $this->provenance);
    }

    public function withCost(Cost $cost): self
    {
        return new self($this->timings, $cost, $this->savings, $this->previous, $this->pruning, $this->provenance);
    }

    public function withSavings(Savings $savings): self
    {
        return new self($this->timings, $this->cost, $savings, $this->previous, $this->pruning, $this->provenance);
    }

    /** This account, with what pruning did in the run (ADR-0025). */
    public function withPruning(PruningAccount $pruning): self
    {
        return new self($this->timings, $this->cost, $this->savings, $this->previous, $pruning, $this->provenance);
    }

    /** What pruning did in the run; nothing where it pruned nothing. */
    public function pruning(): PruningAccount
    {
        return $this->pruning;
    }

    /** This account, after the runs `trend.json` held before it, which the flows read once on the default branch. */
    public function after(Trend $previous): self
    {
        return new self($this->timings, $this->cost, $this->savings, $previous, $this->pruning, $this->provenance);
    }

    public function timings(): RunTimings|Untimed
    {
        return $this->timings;
    }

    public function cost(): Cost|Untimed
    {
        return $this->cost;
    }

    public function savings(): Savings|NoHistory
    {
        return $this->savings;
    }

    /**
     * What the default branch's runs since this instant saved, this one among
     * them: each one's full one-job run less its runner time; no history off
     * the default branch, or where no run knew both.
     */
    public function savedSince(Instant $since): Seconds|NoHistory
    {
        if ($this->previous instanceof NoTrend) {
            return NoHistory::yet();
        }

        $before = $this->previous->savedSince($since);
        $own = $this->timings instanceof RunTimings && $this->savings instanceof Savings
            ? Seconds::of(max(0.0, $this->savings->fullRun()->seconds() - $this->timings->spent()->runner()->seconds()))
            : NoHistory::yet();

        return match (true) {
            $own instanceof NoHistory => $before,
            $before instanceof NoHistory => $own,
            default => Seconds::of($before->seconds() + $own->seconds()),
        };
    }

    /** The runs `trend.json` held before this one; no trend off the default branch. */
    public function previous(): Trend|NoTrend
    {
        return $this->previous;
    }
}
