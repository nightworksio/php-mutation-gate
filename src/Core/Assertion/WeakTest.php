<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;

/**
 * A test whose every assertion checks only that something is there, or its
 * shape, and those assertions. Only a test its runner names is read, so a
 * weak test has a name: its file and description (ADR-0014, decision 6).
 */
final readonly class WeakTest
{
    private function __construct(
        private TestId $test,
        private TestName $name,
        private Assertion $first,
        private Assertions $assertions,
    ) {
    }

    /** A test, by its id and name, and its assertions, one at least. */
    public static function of(TestId $test, TestName $name, Assertion $first, Assertion ...$more): self
    {
        return new self($test, $name, $first, Assertions::of($first, ...$more));
    }

    public function test(): TestId
    {
        return $this->test;
    }

    /** The whole test, by its file and description, with any dataset row folded in. */
    public function name(): TestName
    {
        return $this->name;
    }

    public function assertions(): Assertions
    {
        return $this->assertions;
    }

    /**
     * The style the test asserts in: that of its first weak assertion, each
     * of a weak test's assertions being weak, so a PHPUnit class Pest runs
     * that uses `expect()` first asserts as Pest does.
     */
    public function style(): AssertionStyle
    {
        return $this->first->style();
    }
}
