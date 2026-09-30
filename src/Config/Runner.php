<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

/** `runner`: the tool that mutates (ADR-0004). */
final readonly class Runner
{
    private function __construct(private string $json)
    {
    }

    public static function pest(): self
    {
        return self::uses('pest');
    }

    public static function infection(): self
    {
        return self::uses('infection');
    }

    /** A runner another extension registers by name, or a class, with its options. */
    public static function uses(string $runner, Option ...$options): self
    {
        return new self(Option::choice($runner, ...$options));
    }

    public function written(): string
    {
        return $this->json;
    }
}
