<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ListsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The tests the runner lists, once over the suites whose tests judge every
 * unit and once over those whose tests judge only the units they hold, where
 * the config lists any (ADR-0002): a hold the first lists no test
 * of, and the second does, adds judges and narrows nothing (ADR-0005). A
 * runner that lists side by side runs both listings at once.
 *
 * @internal
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
        $suites = $adapters->narrowing->holdingSuites();
        $runner = $adapters->runner;
        [$judging, $holding] = $suites instanceof Suites && $runner instanceof ListsSideBySide
            ? self::sideBySide($runner, $adapters->withheld, $everyUnit, $suites)
            : self::oneAfterAnother($adapters, $everyUnit, $suites);

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
     * Both listings, side by side.
     *
     * @return array{TestListing|CannotJudge, TestListing|CannotJudge}
     */
    private static function sideBySide(
        ListsSideBySide $runner,
        Withheld $withheld,
        Suites $judging,
        Suites $holding,
    ): array {
        $listed = $runner->listedSideBySide($withheld, $judging, $holding);

        return [$listed[0], $listed[1]];
    }

    /**
     * The listing over the suites that judge every unit, and then, where it
     * lists and the config lists holding suites, the listing over those.
     *
     * @return array{TestListing|CannotJudge, TestListing|CannotJudge}
     */
    private static function oneAfterAnother(Adapters $adapters, Suites $everyUnit, Suites|NotGiven $suites): array
    {
        $judging = $adapters->runner->listing($adapters->withheld, $everyUnit);

        return [$judging, $suites instanceof Suites && $judging instanceof TestListing
            ? $adapters->runner->listing($adapters->withheld, $suites)
            : TestListing::none()];
    }
}
