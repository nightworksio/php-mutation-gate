<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Untested;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a plan selects: the units it runs, and what it considered, with the
 * new code and why it reached what it did; or why it cannot select. A plan
 * cuts its shards from it, and a plan that stops at its selection writes it
 * alone (ADR-0290).
 *
 * @internal
 */
final readonly class Selection
{
    private const string LABEL = 'selection';

    private const string UNNAMED = 'A plan that stops at its selection names no tests (ADR-0290).';

    private function __construct(private Units $toRun, private Considered|CannotJudge $considered)
    {
    }

    /**
     * The units to run, and what the plan considered: what it kept, with
     * the new code, why it reached it, the new code no test runs and the
     * sample, where each can be told.
     */
    public static function of(
        Units $toRun,
        Considered $kept,
        Changes|CannotJudge $changed,
        Reasons $reach,
        CoverageMap $map,
        Sample|CannotJudge $sample,
    ): self {
        $considered = match (true) {
            $changed instanceof CannotJudge => $changed,
            $sample instanceof CannotJudge => $sample,
            default => $kept->reaching($changed, $reach)->untesting(Untested::of($changed, $map))->sampling($sample),
        };

        return new self($toRun, $considered);
    }

    public function considered(): Considered|CannotJudge
    {
        return $this->considered;
    }

    /**
     * The plan of this selection alone, made on the standing's head: the
     * units to run as one uncut shard, with what it considered, untimed and
     * naming no test. Its file is marked, so that no shard runs it.
     */
    public function alone(Standing $standing, Digest $base, Keys $keys): Plan|CannotJudge
    {
        $uncut = Shard::of(ShardId::of(1), Package::at(Path::root()), $this->toRun, Seconds::none(), self::LABEL);

        return $this->considered instanceof CannotJudge
            ? $this->considered
            : Plan::of($standing->head(), $base, $keys, Shards::of($uncut))
                ->on($standing->runOn())
                ->considering($this->considered)
                ->naming(CannotJudge::because(self::UNNAMED));
    }
}
