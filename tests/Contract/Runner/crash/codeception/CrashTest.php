<?php

declare(strict_types=1);

namespace Tests;

use Codeception\Test\Unit;
use Library\Crash;

// Made greater than or equal, the check ends the process before any test fails.
final class CrashTest extends Unit
{
    public function testSettlesACodeOfNone(): void
    {
        $this->assertSame(0, new Crash()->settle(0));
    }
}
