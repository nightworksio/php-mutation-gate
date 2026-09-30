<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

/** Where an extension came from: the Composer package that names it. */
final readonly class Origin
{
    private function __construct(private string $name)
    {
    }

    public static function of(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }
}
