<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/** Text that would be larger than a reader takes, and so is not read. */
final readonly class TooLarge
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
