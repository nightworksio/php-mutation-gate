<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_map;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Traversable;

/**
 * What kept a run from judging honestly, in the order it was met (ADR-0016),
 * then each set of tests that fails without any mutant, as its own line
 * (ADR-0008). Any one of them makes the verdict *cannot judge*.
 *
 * @implements IteratorAggregate<int, CannotJudge>
 */
final readonly class Obstacles implements Countable, IteratorAggregate
{
    /** @param list<CannotJudge> $obstacles */
    private function __construct(private array $obstacles, private UnmutatedFailures $failing)
    {
    }

    public static function none(): self
    {
        return new self([], UnmutatedFailures::none());
    }

    public function with(CannotJudge $obstacle): self
    {
        return new self([...$this->obstacles, $obstacle], $this->failing);
    }

    /** These obstacles, with these sets of tests that fail without any mutant. */
    public function failing(UnmutatedFailures $failing): self
    {
        return new self($this->obstacles, $failing);
    }

    /** What kept the run from judging, in the order it was met, without the tests that fail without any mutant. */
    public function met(): self
    {
        return new self($this->obstacles, UnmutatedFailures::none());
    }

    /** The sets of tests that fail without any mutant, each with the mutants it leaves out of every score. */
    public function unmutatedFailures(): UnmutatedFailures
    {
        return $this->failing;
    }

    public function count(): int
    {
        return count($this->obstacles) + count($this->failing);
    }

    /** @return Traversable<int, CannotJudge> each obstacle, then each set of failing tests' line */
    public function getIterator(): Traversable
    {
        $lines = array_map(
            static fn(FailingTests $tests): CannotJudge => CannotJudge::because($tests->line()),
            [...$this->failing],
        );

        return new ArrayIterator([...$this->obstacles, ...$lines]);
    }
}
