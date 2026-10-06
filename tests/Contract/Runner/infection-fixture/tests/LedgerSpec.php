<?php

declare(strict_types=1);

namespace Tests;

use Beside\Ledger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LedgerSpec extends TestCase
{
    #[Test]
    public function balancesWhatCameInAgainstWhatWentOut(): void
    {
        self::assertSame(2, new Ledger()->balance(5, 3));
    }
}
