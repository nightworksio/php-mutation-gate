<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * The order a mutant's own process started its tests in, counted and
 * digested as each starts (ADR-0014). A test fails or errors
 * while it runs, after it started and before the next does, so a killer
 * that is the test started last stands where the count does; whatever
 * else is named a killer, as a class whose set-up failed, stands nowhere.
 * A test is running from its start until it finishes, is named a killer, or
 * the run ends.
 */
final class RunOrder
{
    private int $started = 0;

    private string $last = '';

    private string $running = '';

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
        $this->running = $test;
        $this->digest = $this->digest->with(TestId::of($test));
    }

    /** The test running stopped: it finished, was named a killer, or the run ended. */
    public function stopped(): void
    {
        $this->running = '';
    }

    /** The test running, by its id; none between tests, before the first or once the run ended. */
    public function running(): string|NotGiven
    {
        return $this->running === '' ? NotGiven::value() : $this->running;
    }

    /** Where a killer stands, by its id. */
    public function placed(string $test): Placed
    {
        return $this->started > 0 && $test === $this->last
            ? Placed::at($this->started, $this->digest->value(), $this->run)
            : Placed::unplaced($this->run);
    }
}
