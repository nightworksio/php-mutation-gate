<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * The order a mutant's own process started its tests in, counted and
 * digested as each starts (ADR-0014, decision 16). A test fails or errors
 * while it runs, after it started and before the next does, so a killer
 * that is the test started last stands where the count does; whatever
 * else is named a killer, as a class whose set-up failed, stands nowhere.
 */
final class RunOrder
{
    private int $started = 0;

    private string $last = '';

    private OrderDigest $digest;

    /** The order of the process with this id. */
    public function __construct(private readonly int $run)
    {
        $this->digest = OrderDigest::start();
    }

    /** A test that started, by its id. */
    public function started(string $test): void
    {
        $this->started++;
        $this->last = $test;
        $this->digest = $this->digest->with(TestId::of($test));
    }

    /** Where a killer stands, by its id. */
    public function placed(string $test): Placed
    {
        return $this->started > 0 && $test === $this->last
            ? Placed::at($this->started, $this->digest->value(), $this->run)
            : Placed::unplaced($this->run);
    }
}
