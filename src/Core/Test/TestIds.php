<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Tests in the order they were added, each once.
 *
 * @implements IteratorAggregate<int, TestId>
 */
final readonly class TestIds implements Countable, IteratorAggregate
{
    /** @param array<string, TestId> $tests by id */
    private function __construct(private array $tests)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(TestId ...$tests): self
    {
        $collected = [];

        foreach ($tests as $test) {
            $collected[$test->value()] = $test;
        }

        return new self($collected);
    }

    public function with(TestId $test): self
    {
        $tests = $this->tests;
        $tests[$test->value()] = $test;

        return new self($tests);
    }

    public function has(TestId $test): bool
    {
        return array_key_exists($test->value(), $this->tests);
    }

    public function count(): int
    {
        return count($this->tests);
    }

    /** @return Traversable<int, TestId> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->tests));
    }
}
