<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * The suites the project's PHPUnit config declares, in its order.
 *
 * @implements IteratorAggregate<int, DeclaredSuite>
 */
final readonly class DeclaredSuites implements IteratorAggregate
{
    /** @param list<DeclaredSuite> $suites */
    private function __construct(private array $suites)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(DeclaredSuite ...$suites): self
    {
        return new self(array_values($suites));
    }

    /** @return Traversable<int, DeclaredSuite> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->suites);
    }
}
