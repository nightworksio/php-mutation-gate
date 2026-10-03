<?php

declare(strict_types=1);

namespace Tests;

use Library\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DrainSpec extends TestCase
{
    #[Test]
    public function drainsAnAmountToNothing(): void
    {
        self::assertSame(0, new Money()->drain(3));
    }
}
