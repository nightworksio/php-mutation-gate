<?php

declare(strict_types=1);

namespace CodeceptionLibrary\Stock;

use Beside\Stock;
use Codeception\Test\Unit;

final class StockTest extends Unit
{
    public function testLeavesWhatWasHeldLessWhatWasSold(): void
    {
        $this->assertSame(2, new Stock()->left(5, 3));
    }
}
