<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use NightWorksIO\MutationGate\Core\File\Line;

/**
 * A named function or method of a file and the lines it runs over, from
 * its `function` keyword to its closing brace (ADR-0100): a method as
 * `Class::method`, by its class's short name, and a function by its own.
 */
final readonly class FunctionSpan
{
    private function __construct(private string $name, private Line $first, private Line $last)
    {
    }

    public static function of(string $name, Line $first, Line $last): self
    {
        return new self($name, $first, $last);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function first(): Line
    {
        return $this->first;
    }

    public function last(): Line
    {
        return $this->last;
    }

    /** Whether a line is in it. */
    public function holds(Line $line): bool
    {
        return $this->first->number() <= $line->number() && $line->number() <= $this->last->number();
    }

    /** How many lines it runs over. */
    public function width(): int
    {
        return $this->last->number() - $this->first->number();
    }
}
