<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/** Something was written, and where it went. */
final readonly class Written
{
    private function __construct(private string $where) {}

    public static function to(string $where): self
    {
        return new self($where);
    }

    public function where(): string
    {
        return $this->where;
    }
}
