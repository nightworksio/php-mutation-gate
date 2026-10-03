<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The library's tests the runner contract judges every runner by, as the other libraries hold them. */
final class MoneySpec extends TestCase
{
    /** Probes its process once the mutated file has run, which the gate's guard asks of a mutant's run. */
    #[Test]
    #[Group('mutation-canary')]
    public function addsTwoAmounts(): void
    {
        $sum = new Money()->add(2, 3);
        Probe::memory();
        Probe::hog();

        self::assertSame(5, $sum);
    }

    #[Test]
    public function tellsALargeAmountFromASmallOne(): void
    {
        self::assertTrue(new Money()->isLarge(500));
        self::assertFalse(new Money()->isLarge(1));
    }
}
