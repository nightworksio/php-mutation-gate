<?php

declare(strict_types=1);

namespace Tests;

use Library\Crash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Made greater than or equal, the check ends the process before any test fails.
final class CrashSpec extends TestCase
{
    #[Test]
    public function settlesACodeOfNone(): void
    {
        self::assertSame(0, new Crash()->settle(0));
    }
}
