<?php

declare(strict_types=1);

namespace Tests;

use Library\Held;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoubleSpec extends TestCase
{
    #[Test]
    public function doublesAnAmount(): void
    {
        self::assertSame(8, new Held()->double(4));
    }
}
