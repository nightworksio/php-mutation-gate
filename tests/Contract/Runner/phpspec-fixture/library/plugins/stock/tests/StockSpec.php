<?php

declare(strict_types=1);

namespace tests;

use Beside\Stock;
use PhpSpec\ObjectBehavior;

final class StockSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Stock::class);
    }

    public function it_leaves_what_was_held_less_what_was_sold(): void
    {
        $this->left(5, 3)->shouldReturn(2);
    }
}
