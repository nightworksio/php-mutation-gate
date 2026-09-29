<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Every judged tree, in the order it was judged.
 *
 * @implements IteratorAggregate<int, TreeVerdict>
 */
final readonly class TreeVerdicts implements Countable, IteratorAggregate
{
    /** @param list<TreeVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(TreeVerdict ...$verdicts): self
    {
        return new self(array_values($verdicts));
    }

    public function with(TreeVerdict $verdict): self
    {
        return new self([...$this->verdicts, $verdict]);
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, TreeVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }
}
