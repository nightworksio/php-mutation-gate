<?php

declare(strict_types=1);

namespace TestoLibrary;

use Library\Money;
use Testo\Assert;
use Testo\Filter\Group;
use Testo\Test;

/** The library's tests the runner contract judges every runner by, as the other libraries hold them. */
final class MoneyTest
{
    /** Probes its process once the mutated file has run, which the gate's guard asks of a mutant's run. */
    #[Test]
    #[Group('mutation-canary')]
    public function addsTwoAmounts(): void
    {
        $sum = new Money()->add(2, 3);
        Probe::memory();
        Probe::hog();

        Assert::same(5, $sum);
    }

    #[Test]
    public function tellsALargeAmountFromASmallOne(): void
    {
        Assert::true(new Money()->isLarge(500));
        Assert::false(new Money()->isLarge(1));
    }
}
