<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * A group must cover what it holds before it may judge it (ADR-0005,
 * decision 10). Before a shard mutates a held unit, its holding tests run
 * alone under coverage, and every line of it the whole suite covers, as the
 * map the plan handed the shard says, must be among theirs. A held unit they
 * miss lines of is not mutated, and fails the verdict; tests that do not pass
 * on their own cannot judge. Of a held unit they cover, the tests of theirs
 * that run it are kept: they are the tests that judge its mutants.
 */
final readonly class HeldCoverage
{
    private const string FAILED
        = 'The tests that hold %s cannot run on their own under coverage, so they cannot judge it. %s';

    public function __construct(private Adapters $adapters)
    {
    }

    /**
     * The shard's held units whose holding tests miss lines of them, by the
     * map the plan handed the shard, and those they cover, with the tests of
     * theirs that run each. A unit the whole suite judges is not checked, and
     * is mutated as any other.
     */
    public function checked(Shard $shard, CoverageMap $suite): HeldChecks|CannotJudge
    {
        $checks = HeldChecks::none();

        foreach ($shard->units() as $unit) {
            $judgedBy = $unit->judgedBy();

            if ($judgedBy instanceof WholeSuite) {
                continue;
            }

            $group = $this->adapters->runner->coverage(
                $this->adapters->covering(CoverageRun::of($judgedBy, Workspace::heldCoverage($shard->id()))),
            );

            if ($group instanceof CannotJudge) {
                return CannotJudge::because(sprintf(self::FAILED, $unit->path()->value(), $group->why()));
            }

            $checks = $checks->with(GroupCoverage::of($unit, $suite, $group));
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

        return Shard::of($shard->id(), $shard->package(), $kept, $shard->cost(), $shard->label());
    }
}
