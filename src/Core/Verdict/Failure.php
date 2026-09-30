<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * Something that fails the run whatever the scores, such as a stale ignore,
 * a held path its tests do not cover, or a floor lowered without a reason.
 */
final readonly class Failure
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
