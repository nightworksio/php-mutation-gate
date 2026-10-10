<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/**
 * A set of the tests a coverage map knows, by the name the map gives it: the
 * same name for the same set anywhere in the map, so what is worked out of a
 * set is worked out once.
 */
final readonly class TestSet
{
    private function __construct(private string $name)
    {
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }
}
