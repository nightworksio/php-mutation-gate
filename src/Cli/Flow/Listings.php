<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The tests the runner lists, once over the suites whose tests judge every
 * unit and once over those whose tests judge only the units they hold, where
 * the config lists any (ADR-0002, decision 8): a hold the first lists no test
 * of, and the second does, adds judges and narrows nothing (ADR-0005,
 * decision 9).
 */
final readonly class Listings
{
    private function __construct(public TestListing $judging, public TestListing $holding)
    {
    }

    /** What the runner lists over each list of suites; or why it lists nothing. */
    public static function of(Adapters $adapters): self|CannotJudge
    {
        $everyUnit = $adapters->narrowing->suitesFor(WholeSuite::tests());
        $judging = $adapters->runner->listing($adapters->withheld, $everyUnit);
        $suites = $adapters->narrowing->holdingSuites();
        $holding = $suites instanceof Suites && $judging instanceof TestListing
            ? $adapters->runner->listing($adapters->withheld, $suites)
            : TestListing::none();

        return match (true) {
            $judging instanceof CannotJudge => $judging,
            $holding instanceof CannotJudge => $holding,
            default => new self($judging, $holding),
        };
    }

    /** Every group either listing lists. */
    public function groups(): Groups
    {
        $groups = $this->judging->groups();

        foreach ($this->holding->groups() as $group) {
            $groups = $groups->with($group);
        }

        return $groups;
    }
}
