<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use Closure;

/**
 * One change `doctor --fix` makes to a file (ADR-0083): the file, as the
 * project or the file system's root spells it, and what its text becomes,
 * from the text before it, so changes of one file apply in turn.
 *
 * @internal
 */
final readonly class Change
{
    /** @param Closure(string): string $becomes */
    private function __construct(private string $file, private Closure $becomes)
    {
    }

    /** @param Closure(string): string $becomes what the file's text becomes, from its text before */
    public static function of(string $file, Closure $becomes): self
    {
        return new self($file, $becomes);
    }

    public function file(): string
    {
        return $this->file;
    }

    /** What the file's text becomes, from this text. */
    public function applied(string $text): string
    {
        return ($this->becomes)($text);
    }
}
