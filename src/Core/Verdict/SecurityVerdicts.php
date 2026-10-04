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
 * The security set of each package (ADR-0021, decision 16); none where no
 * security-tagged mutator is turned on.
 *
 * @implements IteratorAggregate<int, SecurityVerdict>
 */
final readonly class SecurityVerdicts implements Countable, IteratorAggregate
{
    /** @param list<SecurityVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(SecurityVerdict ...$verdicts): self
    {
        return new self(array_values($verdicts));
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, SecurityVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }
}
