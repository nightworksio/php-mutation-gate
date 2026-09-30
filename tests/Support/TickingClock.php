<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function sprintf;

/** A clock that moves on by the same number of seconds each time it is read, from a moment. */
final class TickingClock implements ClockInterface
{
    private int $reads = 0;

    public function __construct(private readonly string $from, private readonly int $step)
    {
    }

    public function now(): DateTimeImmutable
    {
        $now = new DateTimeImmutable($this->from)->modify(sprintf('+%d seconds', $this->reads * $this->step));
        ++$this->reads;

        return $now;
    }
}
