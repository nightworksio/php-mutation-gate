<?php

declare(strict_types=1);

namespace CodeceptionLibrary;

use Codeception\Test\Unit;
use Library\Stall;

use function usleep;

// Each test takes more than half a second, so the silence limit of the
// slowest falls seconds short of the limit of all three.
final class StallTest extends Unit
{
    public function testDrainsAFirstAmount(): void
    {
        usleep(600_000);

        $this->assertSame(0, new Stall()->drain(1));
    }

    public function testDrainsASecondAmount(): void
    {
        usleep(600_000);

        $this->assertSame(0, new Stall()->drain(2));
    }

    public function testDrainsAThirdAmount(): void
    {
        usleep(600_000);

        $this->assertSame(0, new Stall()->drain(3));
    }
}
