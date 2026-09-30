<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Loading this file leaves a mark where CONTRACT_LOADED names one: the runner
// contract's check that a run of no test loads no test file.
if (getenv('CONTRACT_LOADED') !== false) {
    touch((string) getenv('CONTRACT_LOADED'));
}

final class MoneySpec extends TestCase
{
    #[Test]
    #[Group('mutation-canary')]
    public function addsTwoAmounts(): void
    {
        self::assertSame(5, new Money()->add(2, 3));
    }

    #[Test]
    public function tellsALargeAmountFromASmallOne(): void
    {
        self::assertTrue(new Money()->isLarge(500));
        self::assertFalse(new Money()->isLarge(1));
    }
}
