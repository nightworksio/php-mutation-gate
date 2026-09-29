<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The name an extension registers an adapter or a preset under, and a config chooses it by: `pest`, `sarif`. */
final readonly class Name
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
