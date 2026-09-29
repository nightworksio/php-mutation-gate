<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** Something every report shows that does not change the verdict, such as an ignore about to expire. */
final readonly class Warning
{
    private function __construct(private string $text) {}

    public static function that(string $text): self
    {
        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }
}
