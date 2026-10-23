<?php

declare(strict_types=1);

namespace tests;

use Library\Money;
use PhpSpec\ObjectBehavior;

/** The library's examples the runner contract judges every runner by, as the other libraries hold them. */
final class MoneySpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Money::class);
    }

    /** Probes its process once the mutated file has run, which the gate's guard asks of a mutant's run. */
    public function it_adds_two_amounts(): void
    {
        $sum = $this->add(2, 3);
        Probe::memory();
        Probe::hog();

        $sum->shouldReturn(5);
    }

    public function it_tells_a_large_amount_from_a_small_one(): void
    {
        $this->isLarge(500)->shouldReturn(true);
        $this->isLarge(1)->shouldReturn(false);
    }
}
