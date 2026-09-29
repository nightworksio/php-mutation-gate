<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** A line of a file, counted from 1. */
final readonly class Line
{
    private function __construct(private int $number) {}

    public static function of(int $number): self
    {
        return new self($number);
    }

    public function number(): int
    {
        return $this->number;
    }
}
