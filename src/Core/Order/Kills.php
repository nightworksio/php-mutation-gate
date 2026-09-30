<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Test\TestId;

/** How many times one test was the first to kill something. */
final readonly class Kills
{
    /** @param positive-int $count */
    private function __construct(private TestId $test, private int $count)
    {
    }

    /** @param positive-int $count */
    public static function of(TestId $test, int $count): self
    {
        return new self($test, $count);
    }

    /** The one kill of a test that had killed nothing before. */
    public static function first(TestId $test): self
    {
        return new self($test, 1);
    }

    /** These kills, and one more. */
    public function andOneMore(): self
    {
        return new self($this->test, $this->count + 1);
    }

    public function test(): TestId
    {
        return $this->test;
    }

    /** @return positive-int */
    public function count(): int
    {
        return $this->count;
    }
}
