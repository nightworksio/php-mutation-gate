<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_shift;
use function array_values;
use function count;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function sprintf;

/** A clock that reads these seconds after a moment, one per read, and the last of them once they run out. */
final class ScriptedClock implements ClockInterface
{
    /** @var list<int> */
    private array $seconds;

    public function __construct(private readonly string $from, int ...$seconds)
    {
        $this->seconds = array_values($seconds);
    }

    public function now(): DateTimeImmutable
    {
        $read = count($this->seconds) > 1 ? array_shift($this->seconds) : ($this->seconds[0] ?? 0);

        return new DateTimeImmutable($this->from)->modify(sprintf('+%d seconds', $read));
    }
}
