<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\GroupCoverage;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
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
 * on their own cannot judge.
 */
final readonly class HeldCoverage
{
    private const string FAILED
        = 'The tests that hold %s cannot run on their own under coverage, so they cannot judge it. %s';

    public function __construct(private Adapters $adapters)
    {
    }

    /** The shard's held units whose holding tests miss lines of them, by the map the plan handed the shard. */
    public function misses(Shard $shard, CoverageMap $suite): HeldMisses|CannotJudge
    {
        $misses = HeldMisses::none();

        foreach ($shard->units() as $unit) {
            $judgedBy = $unit->judgedBy();
            $group = $judgedBy instanceof WholeSuite ? $suite : $this->adapters->runner->coverage(
                CoverageRun::of($judgedBy, Workspace::heldCoverage($shard->id()))
                    ->withholding($this->adapters->withheld),
            );

            if ($group instanceof CannotJudge) {
                return CannotJudge::because(sprintf(self::FAILED, $unit->path()->value(), $group->why()));
            }

            $covered = GroupCoverage::of($unit, $suite, $group);
            $misses = $covered instanceof NotCovered ? $misses->with($covered) : $misses;
        }

        return $misses;
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
