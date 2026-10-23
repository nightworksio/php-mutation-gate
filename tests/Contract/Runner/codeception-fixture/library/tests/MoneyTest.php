<?php

declare(strict_types=1);

namespace CodeceptionLibrary;

use Codeception\Attribute\Group;
use Codeception\Test\Unit;
use Library\Money;

/** The library's tests the runner contract judges every runner by, as the other libraries hold them. */
final class MoneyTest extends Unit
{
    /** Probes its process once the mutated file has run, which the gate's guard asks of a mutant's run. */
    #[Group('mutation-canary')]
    public function testAddsTwoAmounts(): void
    {
        $sum = new Money()->add(2, 3);
        Probe::memory();
        Probe::hog();

        $this->assertSame(5, $sum);
    }

    public function testTellsALargeAmountFromASmallOne(): void
    {
        $this->assertTrue(new Money()->isLarge(500));
        $this->assertFalse(new Money()->isLarge(1));
    }
}
