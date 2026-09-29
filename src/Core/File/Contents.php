<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** What a file holds. */
final readonly class Contents
{
    private function __construct(private string $text) {}

    public static function of(string $text): self
    {
        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }
}
