<?php

declare(strict_types=1);

namespace TestoLibrary\Stock;

use Beside\Stock;
use Testo\Assert;
use Testo\Test;

final class StockTest
{
    #[Test]
    public function leavesWhatWasHeldLessWhatWasSold(): void
    {
        Assert::same(2, new Stock()->left(5, 3));
    }
}
