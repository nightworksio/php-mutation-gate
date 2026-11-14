<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff;
use function function_exists;
use function is_int;
use function min;
use function outbreak_steps;
use function outbreak_steps_bind;
use function outbreak_steps_limit;
use function outbreak_steps_share;

use const PHP_INT_MAX;

use function range;

use const SIGXCPU;

/**
 * The steps a split stream's children are held to, where the stream counts
 * them (ADR-0147): each child takes a slot of limits no other running child
 * holds, unlimited from its fork until its parent ends the test, then held to
 * the steps its parent took from the fork to the test's end, and its time to
 * `timeouts.most` alone. Where the stream counts no steps, nothing is held,
 * every child takes the first slot, and its time is held to its parent's.
 *
 * @internal
 */
final readonly class ChildSteps
{
    private bool $counted;

    public function __construct(private StreamPlan $plan)
    {
        $this->counted = $plan->limit->countsSteps()
            && function_exists('outbreak_steps_share')
            && outbreak_steps_share($plan->children);
    }

    /**
     * A slot of limits none of these holds; the first where the stream counts no steps.
     *
     * @param list<int> $held the slots the running children hold
     */
    public function freeSlot(array $held): int
    {
        $free = array_diff(range(0, $this->plan->children - 1), $this->counted ? $held : []);

        return $free === [] ? 0 : min($free);
    }

    /** The slot of a child about to fork left unlimited until its parent ends the test. */
    public function opened(int $slot): void
    {
        if ($this->counted) {
            outbreak_steps_limit($slot, PHP_INT_MAX);
        }
    }

    /** In the child, its steps counted against its slot. */
    public function bound(int $slot): void
    {
        if ($this->counted) {
            outbreak_steps_bind($slot);
        }
    }

    /** The child in this slot held to the steps its parent took from its fork to the test's end. */
    public function held(int $slot, int $atFork, int $atEnd): void
    {
        if ($this->counted) {
            outbreak_steps_limit($slot, $this->plan->limit->stepsOf($atFork, $atEnd));
        }
    }

    /** The steps the parent took so far; 0 where the stream counts none. */
    public function taken(): int
    {
        $taken = $this->counted ? outbreak_steps() : 0;

        return is_int($taken) ? $taken : 0;
    }

    /** The seconds a child may run in a test its parent took this long: the most, where the stream counts steps. */
    public function secondsOf(float|false $own): float
    {
        return $this->counted ? $this->plan->limit->longest() : $this->plan->limit->of($own);
    }

    /** Whether a child that ended by this signal ran past its steps. */
    public function exceeded(int $signal): bool
    {
        return $this->counted && $signal === SIGXCPU;
    }
}
