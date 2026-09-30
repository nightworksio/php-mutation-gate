<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Line;

/**
 * A method some test ran, and the lines it spans, as the coverage report of
 * the run that measured it states them. A runner that finds the tests of a
 * mutant on a method's signature by these lines, as Infection does, reads
 * them back from the map.
 */
final readonly class ExecutedMethod
{
    private function __construct(private string $name, private Line $first, private Line $last)
    {
    }

    public static function of(string $name, int $first, int $last): self
    {
        return new self($name, Line::of($first), Line::of($last));
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
}
