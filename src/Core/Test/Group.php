<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** A group of tests, as the runner lists and selects it. */
final readonly class Group
{
    private function __construct(private string $name) {}

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }
}
