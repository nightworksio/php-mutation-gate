<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function count;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function uasort;

/**
 * An order of commands run side by side: the longest-limited first, a
 * command with no limit before any with one, those of equal limit as given;
 * and the way back to the order they were given in, so each end still
 * answers its own command. It changes only when each starts (ADR-0112).
 *
 * @internal
 */
final readonly class LongestFirst
{
    /** @param list<int> $order the place each command was given at, in the order they start */
    private function __construct(private array $order)
    {
    }

    public static function of(Command ...$commands): self
    {
        $limits = [];

        foreach ($commands as $at => $command) {
            $deadline = $command->deadline();
            $limits[$at] = $deadline instanceof Seconds ? $deadline->seconds() : PHP_FLOAT_MAX;
        }

        uasort($limits, static fn(float $one, float $other): int => $other <=> $one);

        return new self(array_keys($limits));
    }

    /** The order they were given in. */
    public static function asGiven(Command ...$commands): self
    {
        return new self(array_keys($commands));
    }

    /**
     * These, in this order.
     *
     * @template T
     *
     * @param  list<T> $given
     * @return list<T>
     */
    public function sorted(array $given): array
    {
        $sorted = [];

        foreach ($this->order as $at) {
            $sorted[] = $given[$at];
        }

        return $sorted;
    }

    /**
     * What answers each, in this order, back in the order they were given in.
     *
     * @template T
     *
     * @param  list<T> $answered
     * @return list<T>
     */
    public function restored(array $answered): array
    {
        if (count($answered) !== count($this->order)) {
            return $answered;
        }

        $restored = [];

        foreach ($this->order as $place => $at) {
            $restored[$at] = $answered[$place];
        }

        $given = [];

        for ($at = 0; $at < count($restored); $at++) {
            $given[] = $restored[$at];
        }

        return $given;
    }
}
