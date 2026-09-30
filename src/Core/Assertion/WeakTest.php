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
    private function __construct(private TestId $test, private TestName $name, private Assertions $assertions)
    {
    }

    public static function of(TestId $test, TestName $name, Assertions $assertions): self
    {
        return new self($test, $name, $assertions);
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
}
