<?php

declare(strict_types=1);

namespace tests;

use Library\Money;
use PhpSpec\ObjectBehavior;

final class DrainSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Money::class);
    }

    public function it_drains_an_amount_to_nothing(): void
    {
        $this->drain(3)->shouldReturn(0);
    }
}
