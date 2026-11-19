<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * What starts listings over sets of suites, each listing the suites' name as
 * its one test, keeping each set asked and how often they were stopped.
 */
final class ListingStarts
{
    /** @var list<list<string>> the suites of each start, joined, in turn */
    public private(set) array $asked = [];

    public private(set) int $stopped = 0;

    /** @return Closure(Suites ...): PendingListings */
    public function starting(): Closure
    {
        return function (Suites ...$suites): PendingListings {
            $joined = [];
            $listed = [];

            foreach ($suites as $among) {
                $joined[] = $among->joined();
                $listed[] = TestListing::of(TestIds::of(TestId::of($among->joined())));
            }

            $this->asked[] = $joined;

            return PendingListings::taking(static fn(): array => $listed, function (): void {
                $this->stopped++;
            });
        };
    }
}
