<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

/** An `extensions` entry: an extension class to load beside those Composer names (ADR-0001). */
final readonly class Load
{
    private function __construct(private string $class)
    {
    }

    public static function extension(string $class): self
    {
        return new self($class);
    }

    public function class(): string
    {
        return $this->class;
    }
}
