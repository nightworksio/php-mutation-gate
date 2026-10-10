<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

/** What the helper wrote back to one request: a JSON text the core reads as untrusted input (ADR-0029). */
final readonly class Answer
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
