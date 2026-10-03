<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

use function sprintf;

/** A hook the gate wrote is gone, and where it was. */
final readonly class Removed
{
    private function __construct(private string $where)
    {
    }

    public static function from(string $where): self
    {
        return new self($where);
    }

    /** The line a command prints for it. */
    public function said(): string
    {
        return sprintf('Removed %s.', $this->where);
    }
}
