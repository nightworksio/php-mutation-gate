<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_values;

use IteratorAggregate;
use Traversable;

/**
 * What one release changed in what a file writes (ADR-0026, decision 2).
 *
 * @implements IteratorAggregate<int, Step>
 */
final readonly class Migration implements IteratorAggregate
{
    /** @param non-empty-list<Step> $steps */
    private function __construct(private string $version, private array $steps)
    {
    }

    /** The changes the release of this version made, in the order they are made. */
    public static function in(string $version, Step $step, Step ...$more): self
    {
        return new self($version, [$step, ...array_values($more)]);
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return Traversable<int, Step> */
    public function getIterator(): Traversable
    {
        yield from $this->steps;
    }
}
