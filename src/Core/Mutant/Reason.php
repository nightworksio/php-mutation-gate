<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/** Why a runner left a mutant unjudged, in the sentence a report prints beside it. */
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
