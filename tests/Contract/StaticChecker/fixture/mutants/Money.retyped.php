<?php

declare(strict_types=1);

namespace StaticCheckFixture;

final class Money
{
    public function __construct(private int $cents)
    {
    }

    public function add(int $cents): string
    {
        return (string) ($this->cents + $cents);
    }
}
