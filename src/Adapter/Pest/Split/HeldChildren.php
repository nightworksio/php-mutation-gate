<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function count;

/**
 * The children a split stream's parent holds (ADR-0117, ADR-0177): each in
 * one of the places it forks into, or let out of its place where the parent
 * needed it while it still ran their test, running on beside the places
 * until the parent's own time of the test says whether it ran past its
 * limit.
 *
 * @internal
 */
final class HeldChildren
{
    /** @var array<int, ForkedChild> each child in a place, by pid */
    private array $placed = [];

    /** @var array<int, ForkedChild> each child let out of its place, by pid */
    private array $released = [];

    /** A child just forked takes a place. */
    public function started(int $pid, ForkedChild $child): void
    {
        $this->placed[$pid] = $child;
    }

    /** How many places the children take. */
    public function placed(): int
    {
        return count($this->placed);
    }

    /** Whether any child runs. */
    public function areAny(): bool
    {
        return $this->placed !== [] || $this->released !== [];
    }

    /** The child of this pid; false where none is. */
    public function of(int $pid): ForkedChild|false
    {
        return match (true) {
            array_key_exists($pid, $this->placed) => $this->placed[$pid],
            array_key_exists($pid, $this->released) => $this->released[$pid],
            default => false,
        };
    }

    /**
     * The slots of limits the children hold.
     *
     * @return list<int>
     */
    public function slots(): array
    {
        $slots = [];

        foreach ($this->placed + $this->released as $child) {
            $slots[] = $child->slot;
        }

        return $slots;
    }

    /** A child that ended is held no more. */
    public function gone(int $pid): void
    {
        unset($this->placed[$pid], $this->released[$pid]);
    }

    /**
     * The children past their limit at this moment.
     *
     * @return array<int, ForkedChild> by pid
     */
    public function lateBy(float $now): array
    {
        $late = [];

        foreach ($this->placed + $this->released as $pid => $child) {
            if ($child->isLate($now)) {
                $late[$pid] = $child;
            }
        }

        return $late;
    }

    /**
     * Whether the children take fewer than this many places, where they take
     * as many letting the first that is due at this moment out of its place.
     */
    public function roomMade(int $places, float $now): bool
    {
        if (count($this->placed) < $places) {
            return true;
        }

        foreach ($this->placed as $pid => $child) {
            if ($child->isDue($now)) {
                unset($this->placed[$pid]);
                $this->released[$pid] = $child;

                return true;
            }
        }

        return false;
    }

    /**
     * Each child of this test held to this many seconds from its fork, as
     * its parent ended the test.
     *
     * @return list<ForkedChild> those children, as now held
     */
    public function heldTo(string $test, float $seconds): array
    {
        $held = [];

        foreach ($this->placed as $pid => $child) {
            if ($child->test === $test) {
                $this->placed[$pid] = $held[] = $child->heldTo($seconds);
            }
        }

        foreach ($this->released as $pid => $child) {
            if ($child->test === $test) {
                $this->released[$pid] = $held[] = $child->heldTo($seconds);
            }
        }

        return $held;
    }

    /** In a child just forked, none of its parent's children held. */
    public function forgotten(): void
    {
        $this->placed = [];
        $this->released = [];
    }
}
