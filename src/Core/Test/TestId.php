<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** A test as its runner names it in a coverage report. */
final readonly class TestId
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
