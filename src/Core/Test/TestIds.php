<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_diff_key;
use function array_intersect_key;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_slice;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\NotGiven;
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

    /**
     * Every test of these sets, each once, in the order the sets first name them.
     *
     * @param iterable<self> $sets
     */
    public static function union(iterable $sets): self
    {
        $tests = [];

        foreach ($sets as $set) {
            $tests += $set->tests;
        }

        return new self($tests);
    }

    public function with(TestId $test): self
    {
        $tests = $this->tests;
        $tests[$test->value()] = $test;

        return new self($tests);
    }

    /** These tests, then those of some more these do not hold, in their order. */
    public function and(self $more): self
    {
        return new self($this->tests + $more->tests);
    }

    /** Those of these tests that some others hold too, in this order. */
    public function among(self $others): self
    {
        return new self(array_intersect_key($this->tests, $others->tests));
    }

    /** Those of these tests that some others do not hold, in this order. */
    public function without(self $left): self
    {
        return new self(array_diff_key($this->tests, $left->tests));
    }

    /** Whether these tests begin with those of some others, in their order. */
    public function startsWith(self $others): bool
    {
        return array_slice(array_keys($this->tests), 0, count($others->tests)) === array_keys($others->tests);
    }

    public function has(TestId $test): bool
    {
        return array_key_exists($test->value(), $this->tests);
    }

    /** The first of these tests; none where there are none. */
    public function first(): TestId|NotGiven
    {
        $first = array_key_first($this->tests);

        return $first === null ? NotGiven::value() : $this->tests[$first];
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
