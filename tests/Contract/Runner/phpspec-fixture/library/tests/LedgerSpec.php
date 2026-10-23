<?php

declare(strict_types=1);

namespace tests;

use Beside\Ledger;
use PhpSpec\ObjectBehavior;

final class LedgerSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Ledger::class);
    }

    public function it_balances_what_came_in_against_what_went_out(): void
    {
        $this->balance(5, 3)->shouldReturn(2);
    }
}
