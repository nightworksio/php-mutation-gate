<?php

declare(strict_types=1);

namespace CodeceptionLibrary;

use Codeception\Test\Unit;
use Library\Money;

final class DrainTest extends Unit
{
    public function testDrainsAnAmountToNothing(): void
    {
        $this->assertSame(0, new Money()->drain(3));
    }
}
