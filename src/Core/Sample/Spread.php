<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function count;

/**
 * The tallies of a set of units, read as a ratio estimate reads them
 * (Cochran): the share of their mutants killed, and how far each unit's
 * kills lie from what that share gives its mutants, as their variance with
 * one degree of freedom spent on the share. A set of fewer than two units,
 * or with no mutant counted, has no variance to read.
 */
final readonly class Spread
{
    /** @param list<Tally> $tallies */
    private function __construct(private array $tallies, private Tally $sum)
    {
    }

    public static function of(Tally ...$tallies): self
    {
        $sum = Tally::none();
        $listed = [];

        foreach ($tallies as $tally) {
            $sum = $sum->plus($tally);
            $listed[] = $tally;
        }

        return new self($listed, $sum);
    }

    /** How many units it holds. */
    public function units(): int
    {
        return count($this->tallies);
    }

    /** Every unit's tally, summed. */
    public function sum(): Tally
    {
        return $this->sum;
    }

    /** Killed over counted, summed over the units; none where nothing is counted. */
    public function ratio(): float
    {
        return $this->sum->counted() > 0 ? $this->sum->killed() / $this->sum->counted() : 0.0;
    }

    /** The mean of the units' counted mutants. */
    public function meanCounted(): float
    {
        return $this->tallies === [] ? 0.0 : $this->sum->counted() / count($this->tallies);
    }

    /**
     * The variance of each unit's kills about what the ratio gives its
     * counted mutants; zero where fewer than two units can say.
     */
    public function variance(): float
    {
        $units = count($this->tallies);

        if ($units < 2 || $this->sum->counted() === 0) {
            return 0.0;
        }

        $ratio = $this->ratio();
        $squares = 0.0;

        foreach ($this->tallies as $tally) {
            $residual = $tally->killed() - $ratio * $tally->counted();
            $squares += $residual * $residual;
        }

        return $squares / ($units - 1);
    }
}
