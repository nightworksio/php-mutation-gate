<?php

declare(strict_types=1);

namespace TestoLibrary;

use Library\Held;
use NightWorksIO\MutationGate\Attribute\Holds;
use Testo\Assert;
use Testo\Test;

// Held::double's line is held by this test, which cannot tell doubling from
// subtracting: held, its mutant survives, while DoubleTest, which does not
// hold it, would kill it.
#[Holds('src/Held.php')]
final class HeldTest
{
    #[Test]
    public function doublesNothingToNothing(): void
    {
        Assert::same(0, new Held()->double(0));
    }
}
