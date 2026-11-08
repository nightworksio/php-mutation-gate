<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Format\Fit;

/**
 * What each mutant of a comment or step summary shows (ADR-0102): how many characters of its diff and hint, and
 * whether it says what to try and how to reproduce it.
 *
 * @internal
 */
final readonly class ItemShown
{
    private function __construct(private int $characters, private bool $tries, private bool $reproduces)
    {
    }

    /** Every part, each diff and hint cut where an entry has always been cut. */
    public static function standard(): self
    {
        return new self(MarkdownItems::SHOWN_CHARACTERS, tries: true, reproduces: true);
    }

    /** As `comment.text.most`, `comment.try` and `comment.reproduce` say. */
    public static function by(CommentShows $shows): self
    {
        return new self($shows->most(Cap::Text), $shows->allows(Toggle::Try), $shows->allows(Toggle::Reproduce));
    }

    /** Text cut to the characters one entry shows of it. */
    public function cut(string $text): string
    {
        return Fit::line($text, $this->characters);
    }

    /** Whether each survivor says what to try to kill it (ADR-0101). */
    public function tries(): bool
    {
        return $this->tries;
    }

    /** Whether each entry says the command that reproduces it. */
    public function reproduces(): bool
    {
        return $this->reproduces;
    }
}
