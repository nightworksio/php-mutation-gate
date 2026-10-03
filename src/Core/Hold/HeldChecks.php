<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

/**
 * What running each held unit's holding tests alone under coverage found:
 * the units they miss lines of, which are not mutated, and those they cover,
 * with the tests of theirs that run each (ADR-0005, decision 10).
 */
final readonly class HeldChecks
{
    private function __construct(private HeldMisses $misses, private HeldCovered $covered)
    {
    }

    public static function none(): self
    {
        return new self(HeldMisses::none(), HeldCovered::none());
    }

    /** These checks, with one more unit's: missed or covered. */
    public function with(Covered|NotCovered $checked): self
    {
        return $checked instanceof Covered
            ? new self($this->misses, $this->covered->with($checked))
            : new self($this->misses->with($checked), $this->covered);
    }

    public function misses(): HeldMisses
    {
        return $this->misses;
    }

    public function covered(): HeldCovered
    {
        return $this->covered;
    }
}
