<?php

declare(strict_types=1);

namespace Tests;

use Library\Stall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function usleep;

// Infection times each test by its whole class. Three classes of a second
// each cover the stalled loop, so the silence limit of the slowest falls
// seconds short of the limit of all three.
final class StallSecondSpec extends TestCase
{
    #[Test]
    public function drainsAnAmount(): void
    {
        usleep(1_000_000);

        self::assertSame(0, new Stall()->drain(3));
    }
}
