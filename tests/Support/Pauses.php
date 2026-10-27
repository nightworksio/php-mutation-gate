<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_keys;

use Closure;

/**
 * The pauses a warm worker under test asked for, kept in constant room: how
 * many, and each length asked, without pausing at all.
 */
final class Pauses
{
    private int $count = 0;

    /** @var array<int, true> each length asked for, in microseconds */
    private array $lengths = [];

    /** @return Closure(int): void */
    public function pausing(): Closure
    {
        return function (int $microseconds): void {
            $this->count++;
            $this->lengths[$microseconds] = true;
        };
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return list<int> each length asked for, in microseconds */
    public function lengths(): array
    {
        return array_keys($this->lengths);
    }
}
