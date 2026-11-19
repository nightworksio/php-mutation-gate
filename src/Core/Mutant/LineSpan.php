<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Line;

/** The lines of a file from a first to a last, both among them, that a run made mutants on (ADR-0193). */
final readonly class LineSpan
{
    private function __construct(private Line $first, private Line $last)
    {
    }

    public static function of(Line $first, Line $last): self
    {
        return new self($first, $last);
    }

    public function first(): Line
    {
        return $this->first;
    }

    public function last(): Line
    {
        return $this->last;
    }

    /** Whether it shares a line with the lines from this first to this last. */
    public function touches(Line $first, Line $last): bool
    {
        return $this->first->number() <= $last->number() && $this->last->number() >= $first->number();
    }
}
