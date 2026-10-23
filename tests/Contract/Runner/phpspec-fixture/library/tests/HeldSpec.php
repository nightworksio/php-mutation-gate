<?php

declare(strict_types=1);

namespace tests;

use Library\Held;
use NightWorksIO\MutationGate\Attribute\Holds;
use PhpSpec\ObjectBehavior;

// Held::double's line is held by this spec, whose example cannot tell
// doubling from subtracting: held, its mutant survives, while DoubleSpec,
// which does not hold it, would kill it.
#[Holds('src/Held.php')]
final class HeldSpec extends ObjectBehavior
{
    public function let(): void
    {
        $this->beAnInstanceOf(Held::class);
    }

    public function it_doubles_nothing_to_nothing(): void
    {
        $this->double(0)->shouldReturn(0);
    }
}
