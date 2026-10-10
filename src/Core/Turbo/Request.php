<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

/** One request to the helper: a JSON text in the helper's protocol, which the core writes (ADR-0029). */
final readonly class Request
{
    private function __construct(private string $text)
    {
    }

    public static function ofText(string $text): self
    {
        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }
}
