<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** A clock that always reads the same moment. */
final readonly class StoppedClock implements ClockInterface
{
    public function __construct(private string $at)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->at);
    }
}
