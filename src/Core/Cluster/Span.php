<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

/** The run of a file's significant tokens a mutant changed, by where the first and the last stand among them. */
final readonly class Span
{
    private function __construct(private int $first, private int $last)
    {
    }

    public static function of(int $first, int $last): self
    {
        return new self($first, $last);
    }

    public function first(): int
    {
        return $this->first;
    }

    public function last(): int
    {
        return $this->last;
    }
}
