<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;
use function array_replace;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Traversable;

/**
 * What a ledger learned of one analyser (ADR-0020, decision 11): each
 * mutator's rejection rate, and how long its checks take. Losing it costs
 * speed, never a verdict.
 *
 * @implements IteratorAggregate<int, RejectionRate>
 */
final readonly class AnalyserHistory implements IteratorAggregate
{
    /** @param array<string, RejectionRate> $rates by mutator */
    private function __construct(private string $analyser, private array $rates, private CheckTime $time)
    {
    }

    /** The history of an analyser, by the name its identity gives, before its first check. */
    public static function of(string $analyser): self
    {
        return new self($analyser, [], CheckTime::none());
    }

    /** This history, with a mutator's rate, replacing the one held. */
    public function withRate(RejectionRate $rate): self
    {
        return clone($this, ['rates' => array_replace($this->rates, [$rate->mutator() => $rate])]);
    }

    /** This history, with the time its checks took, replacing the one held. */
    public function withTime(CheckTime $time): self
    {
        return clone($this, ['time' => $time]);
    }

    /** This history, after a check of a mutant that took these seconds and passed it. */
    public function passed(Mutation $mutation, Seconds $took): self
    {
        return $this->withRate($this->rateOf($mutation)->passed())->withTime($this->time->with($took));
    }

    /** This history, after a check of a mutant that took these seconds and rejected it. */
    public function rejected(Mutation $mutation, Seconds $took): self
    {
        return $this->withRate($this->rateOf($mutation)->rejected())->withTime($this->time->with($took));
    }

    /**
     * This history, after a check after the tests that took these seconds:
     * its time is kept, and no rate, since the survivors a check after the
     * tests sees are no fair sample of a mutator's mutants.
     */
    public function checked(Seconds $took): self
    {
        return $this->withTime($this->time->with($took));
    }

    /**
     * This history and what a run learned of the same analyser, added: each
     * mutator's checks and rejections, and the checks' time.
     */
    public function plus(self $run): self
    {
        $rates = $this->rates;

        foreach ($run->rates as $mutator => $rate) {
            $rates[$mutator] = array_key_exists($mutator, $rates) ? $rates[$mutator]->plus($rate) : $rate;
        }

        return new self($this->analyser, $rates, $this->time->plus($run->time));
    }

    public function analyser(): string
    {
        return $this->analyser;
    }

    /** The rate of a mutation's mutator; unchecked where this history has none. */
    public function rateOf(Mutation $mutation): RejectionRate
    {
        return array_key_exists($mutation->mutator(), $this->rates)
            ? $this->rates[$mutation->mutator()]
            : RejectionRate::unchecked($mutation->mutator());
    }

    public function time(): CheckTime
    {
        return $this->time;
    }

    /**
     * This history and another's of the same analyser, read together: where
     * both know a mutator, this one's rate, and this one's time where it has
     * measured any.
     */
    public function and(self $other): self
    {
        return new self(
            $this->analyser,
            array_replace($other->rates, $this->rates),
            $this->time->checks() > 0 ? $this->time : $other->time,
        );
    }

    /** @return Traversable<int, RejectionRate> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->rates));
    }
}
