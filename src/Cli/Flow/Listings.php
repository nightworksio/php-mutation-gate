<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ListsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The tests the runner lists, once over the suites whose tests judge every
 * unit and once over those whose tests judge only the units they hold, where
 * the config lists any (ADR-0002): a hold the first lists no test
 * of, and the second does, adds judges and narrows nothing (ADR-0005). A
 * runner that lists side by side starts both listings at once, and they run
 * while the gate goes on until it takes them.
 *
 * @internal
 */
final readonly class Listings
{
    private function __construct(public TestListing $judging, public TestListing $holding)
    {
    }

    /**
     * The runner's listings over each list of suites, started side by side
     * where it lists so; else listed one after another once taken.
     */
    public static function started(Adapters $adapters): PendingListings
    {
        $everyUnit = $adapters->narrowing->suitesFor(WholeSuite::tests());
        $suites = $adapters->narrowing->holdingSuites();
        $runner = $adapters->runner;

        return $runner instanceof ListsSideBySide
            ? $runner->listingsStarted($adapters->withheld, $everyUnit, ...$suites instanceof Suites ? [$suites] : [])
            : PendingListings::taking(
                static fn(): array => self::oneAfterAnother($adapters, $everyUnit, $suites),
                static function (): void {
                },
            );
    }

    /** What the listings list, once taken; or why the runner lists nothing. */
    public static function taken(PendingListings $pending): self|CannotJudge
    {
        $listed = $pending->taken();
        $judging = $listed[0];
        $holding = array_key_exists(1, $listed) ? $listed[1] : TestListing::none();

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

    /**
     * The listing over the suites that judge every unit, and then, where it
     * lists and the config lists holding suites, the listing over those.
     *
     * @return list<TestListing|CannotJudge>
     */
    private static function oneAfterAnother(Adapters $adapters, Suites $everyUnit, Suites|NotGiven $suites): array
    {
        $judging = $adapters->runner->listing($adapters->withheld, $everyUnit);

        return [$judging, $suites instanceof Suites && $judging instanceof TestListing
            ? $adapters->runner->listing($adapters->withheld, $suites)
            : TestListing::none()];
    }
}
