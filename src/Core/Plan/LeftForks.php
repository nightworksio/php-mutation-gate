<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_sum;

use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The seconds a left mutant's forks are priced at (ADR-0195): its fork at
 * the likely killer the ledger names, where it names one, and otherwise its
 * covering tests', at each of which a second pass forks it.
 *
 * @internal
 */
final readonly class LeftForks
{
    private function __construct(public float $killers, public float $covering)
    {
    }

    /** A mutant the ledger names a likely killer of, taking these seconds. */
    public static function atKiller(float $seconds): self
    {
        return new self($seconds, 0.0);
    }

    /** A mutant the ledger names no likely killer of, whose covering tests take these seconds together. */
    public static function atEveryTest(float $seconds): self
    {
        return new self(0.0, $seconds);
    }

    /**
     * A mutant's forks: at the first of its likely killers that covers it,
     * or at every covering test where none does.
     *
     * @param array<string, float> $seconds each covering test's seconds, by its id
     */
    public static function byKillers(TestIds $killers, array $seconds): self
    {
        foreach ($killers as $killer) {
            if (array_key_exists($killer->value(), $seconds)) {
                return self::atKiller($seconds[$killer->value()]);
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
