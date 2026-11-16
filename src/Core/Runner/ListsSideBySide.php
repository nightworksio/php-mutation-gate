<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Test\Suites;

/**
 * A runner that lists the tests of several sets of suites side by side while
 * the gate goes on, each as its `listing()` lists them alone.
 *
 * @internal
 */
interface ListsSideBySide
{
    /**
     * What the runner lists over each set of suites, in their order, never
     * seeing the variables withheld, started side by side and taken once the
     * gate needs it.
     */
    public function listingsStarted(Withheld $withheld, Suites ...$suites): PendingListings;
}
