<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

/** The options a config writes beside an adapter, under `with`, as JSON text. */
final readonly class Options
{
    private const string NONE = '{}';

    private function __construct(private string $json) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function ofJson(string $json): self
    {
        return new self($json);
    }

    public function json(): string
    {
        return $this->json;
    }
}
