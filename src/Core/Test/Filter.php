<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** The tests a runner's `--filter` pattern selects, such as the tests a `#[Holds]` names. */
final readonly class Filter
{
    private function __construct(private string $pattern) {}

    public static function matching(string $pattern): self
    {
        return new self($pattern);
    }

    public function pattern(): string
    {
        return $this->pattern;
    }
}
