<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * A split stream's child as its parent keeps it (ADR-0117, ADR-0177): its
 * mutant and test, its slot of limits and the parent's steps at its fork,
 * when it was forked, and when it is stopped. Until the parent ends the
 * test, nothing says how long the test takes on this machine, so the child
 * is held to `timeouts.most` alone, and may leave its place once it ran past
 * what the coverage map's time allows; once the parent ended the test, the
 * child is held to the limit the parent's own time sets.
 *
 * @internal
 */
final readonly class ForkedChild
{
    /**
     * @param float $started  when it was forked
     * @param float $deadline when it is stopped as past its limit
     * @param float $due      past when it may leave its place while its parent still runs its test
     * @param bool  $decided  whether its parent ended its test, which set its limit
     */
    private function __construct(
        public string $mutant,
        public string $test,
        public int $slot,
        public int $atFork,
        private float $started,
        private float $deadline,
        private float $due,
        private bool $decided,
    ) {
    }

    /**
     * A child just forked at this moment, held to this many seconds until its
     * parent ends the test, and leaving its place, where its parent needs
     * it, past that many.
     */
    public static function forked(
        string $mutant,
        string $test,
        int $slot,
        int $atFork,
        float $now,
        float $most,
        float $releasedAfter,
    ): self {
        return new self($mutant, $test, $slot, $atFork, $now, $now + $most, $now + $releasedAfter, decided: false);
    }

    /** Whether it ran past its limit by this moment. */
    public function isLate(float $now): bool
    {
        return $now > $this->deadline;
    }

    /** Whether it may leave its place now: its parent still runs its test, and it ran past the map's time. */
    public function isDue(float $now): bool
    {
        return ! $this->decided && $now > $this->due && $now <= $this->deadline;
    }

    /** It is held to this many seconds from its fork, as its parent ended its test. */
    public function heldTo(float $seconds): self
    {
        return new self(
            $this->mutant,
            $this->test,
            $this->slot,
            $this->atFork,
            $this->started,
            $this->started + $seconds,
            $this->due,
            decided: true,
        );
    }
}
