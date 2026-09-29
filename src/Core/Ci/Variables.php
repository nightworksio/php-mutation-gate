<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_key_exists;

/** The environment variables a CI set for a job, as the adapter that read them hands them on. */
final readonly class Variables
{
    /** @param array<string, string> $values by name */
    private function __construct(private array $values)
    {
    }

    /** @param array<string, string> $values by name */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== '';
    }

    /** A variable's value, and nothing where it is not set. */
    public function valueOf(string $name): string
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : '';
    }
}
