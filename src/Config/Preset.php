<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

/** A preset (ADR-0008): a config fragment an extension registers under a name. */
final readonly class Preset
{
    private function __construct(private string $name)
    {
    }

    public static function library(): self
    {
        return self::named('library');
    }

    public static function laravel(): self
    {
        return self::named('laravel');
    }

    public static function symfony(): self
    {
        return self::named('symfony');
    }

    /** A preset another extension registers. */
    public static function named(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }
}
