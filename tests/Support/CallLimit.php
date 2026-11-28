<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;

/** A count of calls that refuses one more than it allows, so what would call without end fails at once. */
final class CallLimit
{
    private int $calls = 0;

    public function __construct(private readonly int $most, private readonly string $why)
    {
    }

    public function called(): void
    {
        ++$this->calls;

        if ($this->calls > $this->most) {
            throw new LogicException($this->why);
        }
    }
}
