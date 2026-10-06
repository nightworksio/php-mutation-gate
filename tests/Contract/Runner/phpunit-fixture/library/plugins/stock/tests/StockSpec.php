<?php

declare(strict_types=1);

namespace Plugins\Stock\Tests;

use Beside\Stock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StockSpec extends TestCase
{
    #[Test]
    public function leavesWhatWasHeldLessWhatWasSold(): void
    {
        self::assertSame(2, new Stock()->left(5, 3));
    }
}
