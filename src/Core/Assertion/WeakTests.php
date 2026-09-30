<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Weak tests, each once with its data set rows folded in, as its name
 * says (ADR-0014, decision 6), in the order they were found.
 *
 * @implements IteratorAggregate<int, WeakTest>
 */
final readonly class WeakTests implements Countable, IteratorAggregate
{
    /** @param array<string, WeakTest> $tests the first row of each, by its name */
    private function __construct(private array $tests)
    {
    }

    public static function of(WeakTest ...$tests): self
    {
        $by = [];

        foreach ($tests as $test) {
            $name = $test->name()->value();
            $by[$name] = array_key_exists($name, $by) ? $by[$name] : $test;
        }

        return new self($by);
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** @return Traversable<int, WeakTest> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->tests));
    }
}
