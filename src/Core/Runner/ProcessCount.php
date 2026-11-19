<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;

/** How many processes a runner may run side by side. */
final readonly class ProcessCount
{
    /** @param positive-int $count */
    private function __construct(private int $count)
    {
    }

    /** @param positive-int $count */
    public static function of(int $count): self
    {
        return new self($count);
    }

    /** As many processes as this count, and one where it comes to none. */
    public static function atLeastOne(int $count): self
    {
        return new self(max(1, $count));
    }

    /** One process: what a runner runs where nothing asks for more. */
    public static function single(): self
    {
        return new self(1);
    }

    /** @return positive-int */
    public function count(): int
    {
        return $this->count;
    }
}
