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
 * The new-code sets judged: one for the change, or one for each package or
 * module with a floor of its own for new lines. None in a run that is not
 * change-scoped.
 *
 * @implements IteratorAggregate<int, NewCodeVerdict>
 */
final readonly class NewCodeVerdicts implements Countable, IteratorAggregate
{
    /** @param list<NewCodeVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(NewCodeVerdict ...$verdicts): self
    {
        return new self(array_values($verdicts));
    }

    public function with(NewCodeVerdict $verdict): self
    {
        return new self([...$this->verdicts, $verdict]);
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, NewCodeVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }
}
