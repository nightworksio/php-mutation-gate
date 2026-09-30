<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_all;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Every assertion one test makes, in the order it makes them. A test is weak
 * when it makes one at least and every one checks only existence or shape.
 * One the table does not hold, or a test not found, leaves it not assessed
 * (ADR-0025, decision 5).
 *
 * @implements IteratorAggregate<int, Assertion>
 */
final readonly class Assertions implements IteratorAggregate
{
    /** @param list<Assertion> $assertions */
    private function __construct(private array $assertions, private bool $assessed)
    {
    }

    public static function of(Assertion ...$assertions): self
    {
        return new self(array_values($assertions), assessed: true);
    }

    /** The assertions of a test that makes one the table does not hold, or that was not found. */
    public static function notAssessed(): self
    {
        return new self([], assessed: false);
    }

    /** These, and one more. */
    public function with(Assertion $assertion): self
    {
        return new self([...$this->assertions, $assertion], $this->assessed);
    }

    /** Whether the test makes an assertion at least, and checks only that something is there, or its shape. */
    public function isWeak(): bool
    {
        if (! $this->assessed || $this->assertions === []) {
            return false;
        }

        return array_all(
            $this->assertions,
            static fn(Assertion $assertion): bool => $assertion->kind() !== AssertionKind::Value,
        );
    }

    /** @return Traversable<int, Assertion> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->assertions);
    }
}
