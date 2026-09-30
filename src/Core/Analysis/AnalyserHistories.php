<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;
use function array_replace;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * What a ledger learned of each analyser that checked mutants in its scope
 * (ADR-0020, decision 11), by the analyser's name.
 *
 * @implements IteratorAggregate<int, AnalyserHistory>
 */
final readonly class AnalyserHistories implements IteratorAggregate
{
    /** @param array<string, AnalyserHistory> $histories by analyser */
    private function __construct(private array $histories)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These histories, with an analyser's, replacing the one held. */
    public function with(AnalyserHistory $history): self
    {
        return new self(array_replace($this->histories, [$history->analyser() => $history]));
    }

    /** The history of the analyser an identity names; one with nothing learned where none is held. */
    public function of(AnalyserIdentity $analyser): AnalyserHistory
    {
        return array_key_exists($analyser->analyser(), $this->histories)
            ? $this->histories[$analyser->analyser()]
            : AnalyserHistory::of($analyser->analyser());
    }

    /** These histories and another ledger's, read together, each analyser's as {@see AnalyserHistory::and} reads it. */
    public function and(self $other): self
    {
        $histories = $other->histories;

        foreach ($this->histories as $analyser => $history) {
            $histories[$analyser] = array_key_exists($analyser, $other->histories)
                ? $history->and($other->histories[$analyser])
                : $history;
        }

        return new self($histories);
    }

    /** These histories, with what a run learned of each analyser added to what they hold of it. */
    public function plus(self $run): self
    {
        $histories = $this->histories;

        foreach ($run->histories as $analyser => $history) {
            $histories[$analyser] = array_key_exists($analyser, $histories)
                ? $histories[$analyser]->plus($history)
                : $history;
        }

        return new self($histories);
    }

    /** @return Traversable<int, AnalyserHistory> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->histories));
    }
}
