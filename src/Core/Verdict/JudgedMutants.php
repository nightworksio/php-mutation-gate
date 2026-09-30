<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use Traversable;

/**
 * Judged mutants, in the order they were reported.
 *
 * @implements IteratorAggregate<int, JudgedMutant>
 */
final readonly class JudgedMutants implements Countable, IteratorAggregate
{
    /** @param list<JudgedMutant> $mutants */
    private function __construct(private array $mutants)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(JudgedMutant ...$mutants): self
    {
        return new self(array_values($mutants));
    }

    public function with(JudgedMutant $mutant): self
    {
        return new self([...$this->mutants, $mutant]);
    }

    /** These mutants, then those. */
    public function and(self $those): self
    {
        return new self([...$this->mutants, ...$those->mutants]);
    }

    /** Every mutant, marked on a changed line where the reach says so. */
    public function within(Reach $reach): self
    {
        $marked = [];

        foreach ($this->mutants as $mutant) {
            $marked[] = $mutant->within($reach);
        }

        return new self($marked);
    }

    /** Every mutant, with the survivors among these ids proven equivalent. */
    public function provenEquivalent(MutantIds $proven): self
    {
        $judged = [];

        foreach ($this->mutants as $mutant) {
            $judged[] = $proven->has($mutant->mutant()->id()) ? $mutant->provenEquivalent() : $mutant;
        }

        return new self($judged);
    }

    /** The mutants on lines the change added or modified, in reported order. */
    public function changed(): self
    {
        $changed = [];

        foreach ($this->mutants as $mutant) {
            if ($mutant->isOnChangedLine()) {
                $changed[] = $mutant;
            }
        }

        return new self($changed);
    }

    /** The mutants the score counts as not killed: those on changed lines first, each part in reported order. */
    public function survivors(Uncovered $uncovered): self
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

        return new self([...$changed, ...$unchanged]);
    }

    public function counts(): Counts
    {
        return Counts::of($this);
    }

    public function count(): int
    {
        return count($this->mutants);
    }

    /** @return Traversable<int, JudgedMutant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutants);
    }
}
