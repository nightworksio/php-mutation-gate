<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * A runner that lists the tests of several sets of suites side by side, each
 * as its `listing()` lists them alone.
 *
 * @internal
 */
interface ListsSideBySide
{
    /**
     * What the runner lists over each set of suites, in their order, never
     * seeing the variables withheld; or why it lists nothing over one.
     *
     * @return list<TestListing|CannotJudge>
     */
    public function listedSideBySide(Withheld $withheld, Suites ...$suites): array;
}
