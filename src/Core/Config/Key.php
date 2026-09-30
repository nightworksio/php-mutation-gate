<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A key of an adapter's options, under its `with`: `bucket`, `colors`. */
final readonly class Key
{
    private function __construct(private string $value)
    {
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
