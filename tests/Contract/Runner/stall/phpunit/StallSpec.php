<?php

declare(strict_types=1);

namespace Tests;

use Library\Stall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Each test takes most of a second, so the silence limit of the slowest
// falls seconds short of the limit of all three.
final class StallSpec extends TestCase
{
    #[Test]
    public function drainsAFirstAmount(): void
    {
        usleep(800_000);

        self::assertSame(0, new Stall()->drain(1));
    }

    #[Test]
    public function drainsASecondAmount(): void
    {
        usleep(800_000);

        self::assertSame(0, new Stall()->drain(2));
    }

    #[Test]
    public function drainsAThirdAmount(): void
    {
        usleep(800_000);

        self::assertSame(0, new Stall()->drain(3));
    }
}
