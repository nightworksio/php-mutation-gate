<?php

declare(strict_types=1);

namespace Library;

final readonly class Money
{
    public function __construct(private int $cents)
    {
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function cents(): int
    {
        return $this->cents;
    }
}
