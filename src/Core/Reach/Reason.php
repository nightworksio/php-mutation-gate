<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/** One decision about what a change reaches, as the sentence printed for it. */
final readonly class Reason
{
    private function __construct(private string $text)
    {
    }

    public static function that(string $text): self
    {
        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }
}
