<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\Shards;
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
     * is mutated as any other. Where the plan measured every held unit of the
     * shard, its measures are taken and nothing runs (ADR-0161). In a run
     * planned without a fresh map, whose handed map is not the suite's, each
     * held unit is kept with its holding tests, and the verdict checks them
     * against the map the streams recorded (ADR-0135).
     */
    public function checked(Shard $shard, CoverageMap $suite, Briefing $briefing): HeldChecks|CannotJudge
    {
        $measured = $briefing->held();

        if ($measured instanceof MeasuredHolds && $measured->measures($shard->units()) && ! $briefing->isEarly()) {
            return $measured->checksOf($shard->units());
        }

        $checks = HeldChecks::none();
        $listing = $this->listingFor($shard->units());

        foreach ($shard->units() as $unit) {
            $group = $this->groupOf($unit, $suite, $listing, Workspace::heldCoverage($shard->id()));

            if ($group instanceof CannotJudge) {
                return $group;
            }

            $checks = match (true) {
                $group instanceof NotGiven => $checks,
                $briefing->isEarly() => $checks->with(Covered::by($unit, $group->tests())),
                default => $checks->with(GroupCoverage::of($unit, $suite, $group)),
            };
        }

        return $checks;
    }

    /**
     * The plan's briefing, telling every shard what the holding tests of each
     * held unit the shards hold cover, each run alone under coverage once, as
     * the plan is made (ADR-0161); as it was where `holds.coverage` measures
     * in each shard or reads the map, or the plan cut no shards.
     */
    public function briefed(Briefing $briefing, Shards|CannotJudge $shards, CoverageMap $suite): Briefing
    {
        return $this->mode === HeldCoverageMode::Measure && $shards instanceof Shards
            ? $briefing->measuring($this->measured($this->heldIn($shards), $suite))
            : $briefing;
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

    /** What the holding tests of each of these held units cover, each run alone under coverage once. */
    private function measured(Units $units, CoverageMap $suite): MeasuredHolds
    {
        $measured = MeasuredHolds::of([]);

        foreach ($units as $unit) {
            $group = $this->groupOf($unit, $suite, NotGiven::value(), Workspace::heldCoverage(NotGiven::value()));

            $measured = match (true) {
                $group instanceof NotGiven => $measured,
                $group instanceof CannotJudge => $measured->with($unit, $group),
                default => $measured->with($unit, GroupCoverage::of($unit, $suite, $group)),
            };
        }

        return $measured;
    }

    /** Every held unit the shards hold, each once. */
    private function heldIn(Shards $shards): Units
    {
        $held = [];

        foreach ($shards as $shard) {
            foreach ($shard->units() as $unit) {
                $held += $unit->isHeld() ? [$unit->path()->value() => $unit] : [];
            }
        }

        return Units::of(...array_values($held));
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

    /**
     * The coverage of the tests that hold a unit, run alone into this
     * directory ({@see HeldRun}), or read from the map for a group listed; nothing for a unit
     * the whole suite judges, or why its tests cannot run on their own.
     */
    private function groupOf(
        Unit $unit,
        CoverageMap $suite,
        TestListing|CannotJudge|NotGiven $listing,
        Path $into,
    ): CoverageMap|CannotJudge|NotGiven {
        $judgedBy = $unit->judgedBy();

        if ($judgedBy instanceof WholeSuite) {
            return NotGiven::value();
        }

        $group = $judgedBy instanceof Group && $listing instanceof TestListing
            ? $suite->ranBy($listing->inGroup($judgedBy))
            : new HeldRun($this->adapters)->coverage($judgedBy, $into);

        return $group instanceof CannotJudge
            ? CannotJudge::because(sprintf(self::FAILED, $unit->path()->value(), $group->why()))
            : $group;
    }
}
