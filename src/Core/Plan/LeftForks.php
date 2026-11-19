<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_sum;

use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The seconds a left mutant's forks are priced at (ADR-0195, ADR-0196): its
 * first pass's fork at the likely killer the ledger names, where it names
 * one, and its covering tests', at each of which a second pass forks it
 * where the first left it, as it may whether the ledger names a killer or
 * not.
 *
 * @internal
 */
final readonly class LeftForks
{
    private function __construct(public float $killers, public float $covering)
    {
    }

    /** A mutant the ledger names a likely killer of, taking these seconds, its covering tests taking these. */
    public static function atKiller(float $seconds, float $covering = 0.0): self
    {
        return new self($seconds, $covering);
    }

    /** A mutant the ledger names no likely killer of, whose covering tests take these seconds together. */
    public static function atEveryTest(float $seconds): self
    {
        return new self(0.0, $seconds);
    }

    /**
     * A mutant's forks: at the first of its likely killers that covers it,
     * where one does, and at every covering test.
     *
     * @param array<string, float> $seconds each covering test's seconds, by its id
     */
    public static function byKillers(TestIds $killers, array $seconds): self
    {
        foreach ($killers as $killer) {
            if (array_key_exists($killer->value(), $seconds)) {
                return self::atKiller($seconds[$killer->value()], array_sum($seconds));
            }
        }

        return self::atEveryTest(array_sum($seconds));
    }

    /** These forks with another mutant's. */
    public function and(self $other): self
    {
        return new self($this->killers + $other->killers, $this->covering + $other->covering);
    }
}
