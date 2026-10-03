<?php

declare(strict_types=1);

namespace StaticCheckFixture;

final class Wallet
{
    public function total(Money $money): int
    {
        return $money->add(1);
    }
}
