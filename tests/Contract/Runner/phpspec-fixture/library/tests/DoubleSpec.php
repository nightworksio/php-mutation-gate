<?php

declare(strict_types=1);

namespace tests;

use Library\Held;
use PhpSpec\ObjectBehavior;

final class DoubleSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Held::class);
    }

    public function it_doubles_an_amount(): void
    {
        $this->double(4)->shouldReturn(8);
    }
}
