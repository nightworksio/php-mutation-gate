<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use Traversable;

/**
 * The last run's survivors run again first (ADR-0020, decisions 19 and 20),
 * in the order they were taken: a signal only, which enters no ledger, no
 * score and no verdict.
 *
 * @implements IteratorAggregate<int, Recheck>
 */
final readonly class Rechecked implements Countable, IteratorAggregate
{
    /** @param list<Recheck> $rechecks */
    private function __construct(private array $rechecks, private Uncovered $uncovered)
    {
    }

    /** These re-checks, counted as the score counts uncovered mutants. */
    public static function of(Uncovered $uncovered, Recheck ...$rechecks): self
    {
        return new self(array_values($rechecks), $uncovered);
    }

    /**
     * The mutants found again that still survive, as this run judged them.
     *
     * @return list<JudgedMutant>
     */
    public function surviving(): array
    {
        $surviving = [];

        foreach ($this->rechecks as $recheck) {
            $now = $recheck->now();
            $surviving = $now instanceof JudgedMutant && $recheck->stillSurvives($this->uncovered)
                ? [...$surviving, $now]
                : $surviving;
        }

        return $surviving;
    }

    /**
     * The survivors made again by no mutant, as the last run left them.
     *
     * @return list<JudgedMutant>
     */
    public function gone(): array
    {
        $gone = [];

        foreach ($this->rechecks as $recheck) {
            $gone = $recheck->now() instanceof Gone ? [...$gone, $recheck->before()] : $gone;
        }

        return $gone;
    }

    /** How uncovered mutants count, as the score counts them. */
    public function uncovered(): Uncovered
    {
        return $this->uncovered;
    }

    /** How many were found again and no longer survive. */
    public function killed(): int
    {
        return count($this->rechecks) - count($this->surviving()) - count($this->gone());
    }

    public function count(): int
    {
        return count($this->rechecks);
    }

    /** @return Traversable<int, Recheck> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rechecks);
    }
}
