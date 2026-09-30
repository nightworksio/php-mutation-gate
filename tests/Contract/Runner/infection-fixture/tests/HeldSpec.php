<?php

declare(strict_types=1);

namespace Tests;

use Library\Held;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Held::double's line is held by this group, whose test cannot tell doubling
// from subtracting: under the group its mutant survives, while DoubleSpec,
// outside the group, would kill it.
#[Group('holds:src/Held.php')]
final class HeldSpec extends TestCase
{
    #[Test]
    public function doublesNothingToNothing(): void
    {
        self::assertSame(0, new Held()->double(0));
    }
}
