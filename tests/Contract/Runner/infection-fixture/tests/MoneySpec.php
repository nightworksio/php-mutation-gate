<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// The runner contract's marks, each left only where its variable names a
// file: CONTRACT_LOADED when this file loads, and CONTRACT_RAN when a test of
// it runs. tests/marks.php leaves CONTRACT_WRAPPED.
if (getenv('CONTRACT_LOADED') !== false) {
    touch((string) getenv('CONTRACT_LOADED'));
}

final class MoneySpec extends TestCase
{
    #[Test]
    #[Group('mutation-canary')]
    public function addsTwoAmounts(): void
    {
        if (getenv('CONTRACT_RAN') !== false) {
            touch((string) getenv('CONTRACT_RAN'));
        }

        self::assertSame(5, new Money()->add(2, 3));
    }

    #[Test]
    public function tellsALargeAmountFromASmallOne(): void
    {
        self::assertTrue(new Money()->isLarge(500));
        self::assertFalse(new Money()->isLarge(1));
    }
}
