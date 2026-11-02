<?php

declare(strict_types=1);

namespace TestoLibrary;

use Library\Money;
use Testo\Assert;
use Testo\Test;

final class DrainTest
{
    #[Test]
    public function drainsAnAmountToNothing(): void
    {
        Assert::same(0, new Money()->drain(3));
    }
}
