<?php

declare(strict_types=1);

namespace TestoLibrary;

use Library\Held;
use Testo\Assert;
use Testo\Test;

final class DoubleTest
{
    #[Test]
    public function doublesAnAmount(): void
    {
        Assert::same(8, new Held()->double(4));
    }
}
