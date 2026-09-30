<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;
use function array_merge;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Cluster\Clustering;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use Traversable;

/**
 * Judged mutants: those a run reported in full, in the order it reported
 * them, then the kills a ledger proved, in the order it keeps them.
 *
 * @implements IteratorAggregate<int, JudgedMutant|JudgedKill>
 */
final readonly class JudgedMutants implements Countable, IteratorAggregate
{
    /**
     * @param list<JudgedMutant> $mutants
     * @param list<JudgedKill>   $kills
     */
    private function __construct(private array $mutants, private array $kills)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public static function of(JudgedMutant ...$mutants): self
    {
        return new self(array_values($mutants), []);
    }

    /** The kills a ledger proved, judged. */
    public static function kills(JudgedKill ...$kills): self
    {
        return new self([], array_values($kills));
    }

    public function with(JudgedMutant $mutant): self
    {
        return new self([...$this->mutants, $mutant], $this->kills);
    }

    /** These mutants, then those of each set in turn; these kills, then theirs. */
    public function and(self ...$those): self
    {
        $mutants = [$this->mutants];
        $kills = [$this->kills];

        foreach ($those as $set) {
            $mutants[] = $set->mutants;
            $kills[] = $set->kills;
        }

        return new self(array_merge(...$mutants), array_merge(...$kills));
    }

    /** Every mutant and kill, marked on a changed line where the reach says so. */
    public function within(Reach $reach): self
    {
        $marked = [];
        $kills = [];

        foreach ($this->mutants as $mutant) {
            $marked[] = $mutant->within($reach);
        }

        foreach ($this->kills as $kill) {
            $kills[] = $kill->within($reach);
        }

        return new self($marked, $kills);
    }

    /** Every mutant, with the survivors among these ids proven equivalent. */
    public function provenEquivalent(MutantIds $proven): self
    {
        $judged = [];

        foreach ($this->mutants as $mutant) {
            $judged[] = $proven->has($mutant->mutant()->id()) ? $mutant->provenEquivalent() : $mutant;
        }

        return new self($judged, $this->kills);
    }

    /**
     * Every mutant, the survivors and uncovered mutants the score counts
     * that share one cause marked with their cluster, read from each file's
     * source (ADR-0022, decision 15).
     *
     * @param ByPath<Contents> $sources each mutated file that can be read, by its path
     */
    public function clustered(Uncovered $uncovered, ByPath $sources): self
    {
        $candidates = [];

        foreach ($this->mutants as $mutant) {
            $judgement = $mutant->judgement();

            if ($judgement->asksForATest() && $judgement->scoring($uncovered) === Scoring::NotKilled) {
                $candidates[] = $mutant;
            }
        }

        $memberships = Clustering::of($candidates, $sources);
        $marked = [];

        foreach ($this->mutants as $mutant) {
            $id = $mutant->mutant()->id()->value();
            $marked[] = array_key_exists($id, $memberships) ? $mutant->inCluster($memberships[$id]) : $mutant;
        }

        return new self($marked, $this->kills);
    }

    /** The mutants and kills on lines the change added or modified, in order. */
    public function changed(): self
    {
        $changed = [];
        $kills = [];

        foreach ($this->mutants as $mutant) {
            if ($mutant->isOnChangedLine()) {
                $changed[] = $mutant;
            }
        }

        foreach ($this->kills as $kill) {
            if ($kill->isOnChangedLine()) {
                $kills[] = $kill;
            }
        }

        return new self($changed, $kills);
    }

    /**
     * The mutants the score counts as not killed: those on changed lines
     * first, each part in reported order. A kill is never one.
     */
    public function survivors(Uncovered $uncovered): Survivors
    {
        $changed = [];
        $unchanged = [];

        foreach ($this->mutants as $mutant) {
            if ($mutant->judgement()->scoring($uncovered) !== Scoring::NotKilled) {
                continue;
            }

            if ($mutant->isOnChangedLine()) {
                $changed[] = $mutant;

                continue;
            }

            $unchanged[] = $mutant;
        }

        return Survivors::of(...$changed, ...$unchanged);
    }

    public function counts(): Counts
    {
        return Counts::of($this);
    }

    public function count(): int
    {
        return count($this->mutants) + count($this->kills);
    }

    /** @return Traversable<int, JudgedMutant|JudgedKill> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator([...$this->mutants, ...$this->kills]);
    }
}
