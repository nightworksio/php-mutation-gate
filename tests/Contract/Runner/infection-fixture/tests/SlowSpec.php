<?php

declare(strict_types=1);

namespace Tests;

use Library\Slow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function usleep;

// Its class takes over a second, so a run capped at one second skips the
// mutant it covers, and a run capped higher judges it.
final class SlowSpec extends TestCase
{
    #[Test]
    public function reducesAfterAWhile(): void
    {
        usleep(1_200_000);

        self::assertSame(2, new Slow()->reduce(4));
    }
}
