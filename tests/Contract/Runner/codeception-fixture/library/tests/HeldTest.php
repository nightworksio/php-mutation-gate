<?php

declare(strict_types=1);

namespace Tests;

use Codeception\Test\Unit;
use Library\Held;
use NightWorksIO\MutationGate\Attribute\Holds;

// Held::double's line is held by this test, which cannot tell doubling from
// subtracting: held, its mutant survives, while DoubleTest, which does not
// hold it, would kill it.
#[Holds('src/Held.php')]
final class HeldTest extends Unit
{
    public function testDoublesNothingToNothing(): void
    {
        $this->assertSame(0, new Held()->double(0));
    }
}
