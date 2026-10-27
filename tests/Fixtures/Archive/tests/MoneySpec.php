<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneySpec extends TestCase
{
    #[Test]
    public function itAddsTwoAmounts(): void
    {
        self::assertSame(5, new Money(2)->plus(new Money(3))->cents());
    }

    #[Test]
    public function itCallsOneCentPositive(): void
    {
        self::assertTrue(new Money(1)->isPositive());
    }

    #[Test]
    public function itCallsNoCentsNotPositive(): void
    {
        self::assertFalse(new Money(0)->isPositive());
    }

    #[Test]
    public function itCallsADebtNotPositive(): void
    {
        self::assertFalse(new Money(-1)->isPositive());
    }
}
