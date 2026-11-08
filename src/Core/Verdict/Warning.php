<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** Something every report shows that does not change the verdict, such as an ignore about to expire. */
final readonly class Warning
{
    private function __construct(private string $text, private bool $isNote)
    {
    }

    /** What someone should act on: it prints under Warnings. */
    public static function that(string $text): self
    {
        return new self($text, isNote: false);
    }

    /** What is worth knowing and asks nothing of anyone: it prints under Notes, and never blocks. */
    public static function noting(string $text): self
    {
        return new self($text, isNote: true);
    }

    public function isNote(): bool
    {
        return $this->isNote;
    }

    public function text(): string
    {
        return $this->text;
    }
}
