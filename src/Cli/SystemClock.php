<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** The system's clock, read here at the edge and handed on as an instant. */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
