<?php

declare(strict_types=1);

namespace tests;

use Library\Stall;
use PhpSpec\ObjectBehavior;

use function usleep;

// Each example takes most of a second, so the silence limit of the slowest
// falls seconds short of the limit of all three.
final class StallSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Stall::class);
    }

    public function it_drains_a_first_amount(): void
    {
        usleep(800_000);

        $this->drain(1)->shouldReturn(0);
    }

    public function it_drains_a_second_amount(): void
    {
        usleep(800_000);

        $this->drain(2)->shouldReturn(0);
    }

    public function it_drains_a_third_amount(): void
    {
        usleep(800_000);

        $this->drain(3)->shouldReturn(0);
    }
}
