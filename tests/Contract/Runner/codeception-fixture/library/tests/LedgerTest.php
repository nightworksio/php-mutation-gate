<?php

declare(strict_types=1);

namespace CodeceptionLibrary;

use Beside\Ledger;
use Codeception\Test\Unit;

final class LedgerTest extends Unit
{
    public function testBalancesWhatCameInAgainstWhatWentOut(): void
    {
        $this->assertSame(2, new Ledger()->balance(5, 3));
    }
}
