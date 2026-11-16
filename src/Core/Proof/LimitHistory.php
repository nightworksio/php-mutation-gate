<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;

use ArrayIterator;

use function ceil;
use function count;
use function intdiv;
use function max;

use NightWorksIO\MutationGate\Core\Config\LearnedBounds;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Bound;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

use function sort;

use Traversable;

use function usort;

/**
 * What a ledger learned for the limits a config has it learn
 * (ADR-0073): how many times its covering tests' own time each of
 * the newest killed mutants took to be killed, which a learned
 * `timeouts.multiple` is read from, and how many shards each of the newest
 * runs ran at once, which a learned `shards.max` is read from.
 */
final readonly class LimitHistory
{
    /** How many of the newest kills a learned multiple is read from. */
    public const int KILLS = 2000;

    /** How many of the newest runs a learned most shards is read from. */
    public const int RUNS = 10;

    /**
     * @param list<Multiple>     $kills       each kill's time over its covering tests' own time, oldest first
     * @param list<ShardsAtOnce> $concurrency each run's most shards at once, oldest first
     */
    private function __construct(private array $kills, private array $concurrency)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * This history, and these kills, each its time over its covering tests'
     * own time, after it; a kill of no positive multiple is not learned.
     */
    public function afterKills(Multiple ...$kills): self
    {
        $learned = array_values(array_filter($kills, static fn(Multiple $kill): bool => $kill->times() > 0.0));

        return new self([...$this->kills, ...$learned], $this->concurrency);
    }

    /** This history, and runs that ran these many shards at once after it; a run of no shard is not learned. */
    public function afterRuns(ShardsAtOnce ...$runs): self
    {
        $learned = array_values(array_filter($runs, static fn(ShardsAtOnce $run): bool => $run->count() > 0));

        return new self($this->kills, [...$this->concurrency, ...$learned]);
    }

    /** This history, with only the newest so many kills and runs. */
    public function keepingLast(Bound $kills, Bound $runs): self
    {
        return new self(array_slice($this->kills, -$kills->count()), array_slice($this->concurrency, -$runs->count()));
    }

    /** This history, each part of it the other's where this one learned none of it. */
    public function and(self $other): self
    {
        return new self(
            $this->kills === [] ? $other->kills : $this->kills,
            $this->concurrency === [] ? $other->concurrency : $this->concurrency,
        );
    }

    /** @return Traversable<int, Multiple> oldest first */
    public function kills(): Traversable
    {
        return new ArrayIterator($this->kills);
    }

    /** @return Traversable<int, ShardsAtOnce> oldest first */
    public function concurrency(): Traversable
    {
        return new ArrayIterator($this->concurrency);
    }

    /** How many kills this history holds. */
    public function killCount(): int
    {
        return count($this->kills);
    }

    /** How many runs this history holds. */
    public function runCount(): int
    {
        return count($this->concurrency);
    }

    /**
     * The multiple of their covering tests' own time that these bounds' share
     * of the kills took no more than, rounded up to a whole number, within
     * them; none where nothing was killed. A key holds it (ADR-0108), and a
     * whole number moves it only when the kills really move, not with each
     * new kill.
     */
    public function multiple(LearnedBounds $bounds): Multiple|NotGiven
    {
        if ($this->kills === []) {
            return NotGiven::value();
        }

        $sorted = $this->kills;
        usort($sorted, static fn(Multiple $one, Multiple $other): int => $one->times() <=> $other->times());
        $at = max(0, (int) ceil($bounds->percentile() * count($sorted)) - 1);

        return $sorted[$at]->roundedUp()->within(Multiple::of($bounds->least()), Multiple::of($bounds->most()));
    }

    /**
     * The median of the kills' multiples: how many times its covering tests'
     * own time judging a mutant a test kills takes; none where nothing was
     * killed.
     */
    public function pace(): Multiple|NotGiven
    {
        if ($this->kills === []) {
            return NotGiven::value();
        }

        $times = array_map(static fn(Multiple $kill): float => $kill->times(), $this->kills);
        sort($times);
        $middle = intdiv(count($times), 2);

        return Multiple::of(count($times) % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2);
    }

    /** How many shards the runs ran at once, read by this statistic; none where no run was learned. */
    public function shards(LearnedStatistic $statistic): int|NotGiven
    {
        if ($this->concurrency === []) {
            return NotGiven::value();
        }

        $sorted = array_map(static fn(ShardsAtOnce $run): int => $run->count(), $this->concurrency);
        sort($sorted);

        return match ($statistic) {
            LearnedStatistic::Max => $sorted[count($sorted) - 1],
            LearnedStatistic::Median => $sorted[intdiv(count($sorted) - 1, 2)],
        };
    }
}
