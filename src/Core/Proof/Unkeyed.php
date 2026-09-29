<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * A unit whose content key could not be computed, such as with no git or no
 * coverage map, and why. It always runs, and its result is never recorded.
 */
final readonly class Unkeyed
{
    private function __construct(private string $why)
    {
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    public function why(): string
    {
        return $this->why;
    }
}
