<?php

declare(strict_types=1);

namespace TestoLibrary;

use Beside\Ledger;
use Testo\Assert;
use Testo\Test;

final class LedgerTest
{
    #[Test]
    public function balancesWhatCameInAgainstWhatWentOut(): void
    {
        Assert::same(2, new Ledger()->balance(5, 3));
    }
}
