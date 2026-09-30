<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

use function sprintf;

/** Something was written, and where it went. */
final readonly class Written
{
    private function __construct(private string $where)
    {
    }

    public static function to(string $where): self
    {
        return new self($where);
    }

    public function where(): string
    {
        return $this->where;
    }

    /** The line a command prints for it. */
    public function said(): string
    {
        return sprintf('Wrote %s.', $this->where);
    }
}
