<?php

declare(strict_types=1);

namespace tests;

use Library\Crash;
use PhpSpec\ObjectBehavior;

// Made greater than or equal, the check ends the process before any example fails.
final class CrashSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Crash::class);
    }

    public function it_settles_a_code_of_none(): void
    {
        $this->settle(0)->shouldReturn(0);
    }
}
