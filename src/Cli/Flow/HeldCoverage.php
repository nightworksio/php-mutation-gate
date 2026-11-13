<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * A group must cover what it holds before it may judge it (ADR-0005).
 * Before a shard mutates a held unit, its holding tests run alone under
 * coverage, and every line of it the whole suite covers, as the map the
 * plan handed the shard says, must be among theirs. A held unit they miss
 * lines of is not mutated, and fails the verdict; tests that do not pass
 * on their own cannot judge. Of a held unit they cover, the tests of
 * theirs that run it are kept: they are the tests that judge its mutants.
 * Under `holds.coverage: map`, a group's lines are read from that map, its
 * tests as the runner lists them, and nothing runs (ADR-0142).
 *
 * @internal
 */
final readonly class HeldCoverage
{
    private const string FAILED
        = 'The tests that hold %s cannot run on their own under coverage, so they cannot judge it. %s';

    public function __construct(private Adapters $adapters, private HeldCoverageMode $mode = HeldCoverageMode::Measure)
    {
    }

    /**
     * The shard's held units whose holding tests miss lines of them, by the
     * map the plan handed the shard, and those they cover, with the tests of
     * theirs that run each. A unit the whole suite judges is not checked, and
     * is mutated as any other. In a run planned without a fresh map, whose
     * handed map is not the suite's, each held unit is kept with its holding
     * tests, and the verdict checks them against the map the streams
     * recorded (ADR-0135).
     */
    public function checked(Shard $shard, CoverageMap $suite, bool $early = false): HeldChecks|CannotJudge
    {
        $checks = HeldChecks::none();
        $listing = $this->listingFor($shard->units());

        foreach ($shard->units() as $unit) {
            $judgedBy = $unit->judgedBy();
            $group = $judgedBy instanceof WholeSuite
                ? NotGiven::value()
                : $this->coverageOf($shard, $judgedBy, $suite, $listing);

            if ($group instanceof CannotJudge) {
                return CannotJudge::because(sprintf(self::FAILED, $unit->path()->value(), $group->why()));
            }

            $checks = match (true) {
                $group instanceof NotGiven => $checks,
                $early => $checks->with(Covered::by($unit, $group->tests())),
                default => $checks->with(GroupCoverage::of($unit, $suite, $group)),
            };
        }

        return $checks;
    }

    /** The shard, less the held units whose holding tests miss lines of them. */
    public static function kept(Shard $shard, HeldMisses $misses): Shard
    {
        $kept = Units::none();

        foreach ($shard->units() as $unit) {
            $kept = $misses->misses($unit->path()) ? $kept : $kept->with($unit);
        }

        return $shard->holding($kept);
    }

    /**
     * The runner's listing that a group's tests are read from under `holds.coverage: map`, where these units hold
     * any group; none otherwise.
     */
    private function listingFor(Units $units): TestListing|CannotJudge|NotGiven
    {
        $groups = $this->mode === HeldCoverageMode::Map
            ? array_values(array_filter(
                array_map(static fn(Unit $unit): WholeSuite|Group|Filter => $unit->judgedBy(), [...$units]),
                static fn(WholeSuite|Group|Filter $tests): bool => $tests instanceof Group,
            ))
            : [];

        $adapters = $this->adapters;

        return $groups === []
            ? NotGiven::value()
            : $adapters->runner->listing($adapters->withheld, $adapters->narrowing->suitesFor($groups[0]));
    }

    /** The coverage of the tests that hold a unit: read from the map for a group listed, else run alone. */
    private function coverageOf(
        Shard $shard,
        Group|Filter $judgedBy,
        CoverageMap $suite,
        TestListing|CannotJudge|NotGiven $listing,
    ): CoverageMap|CannotJudge {
        return $judgedBy instanceof Group && $listing instanceof TestListing
            ? $suite->ranBy($listing->inGroup($judgedBy))
            : $this->adapters->runner->coverage(
                $this->adapters->covering(CoverageRun::of($judgedBy, Workspace::heldCoverage($shard->id()))),
            );
    }
}
