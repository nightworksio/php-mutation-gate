<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How a plan is cut into shards and what cutting it costs (ADR-0006,
 * ADR-0013, ADR-0016): `shards` and `costs`; and whether Outbreak's core
 * writes and vouches for the maps a plan hands its shards (ADR-0118):
 * `outbreak`.
 */
final readonly class Shards implements Part
{
    /**
     * The seconds one shard is cut to where the config does not say: of
     * work, or, where its streams judge a part of the test files, of wall
     * time (ADR-0195).
     */
    public const int SECONDS = 600;

    /** The most shards a plan cuts, where the config does not say. */
    private const int MOST = 20;

    /** The minutes each shard's CI setup takes before the gate starts, where the config does not say. */
    private const int SETUP_MINUTES = 1;

    /** The seconds a job's gate keeps clear of its target past its own wind-down, where the config does not say. */
    private const int MARGIN_SECONDS = 45;

    /** The minutes between two progress artifacts a CI shard leaves, where the config does not say (ADR-0157). */
    private const int PROGRESS_MINUTES = 3;

    private function __construct(
        private Seconds|Absent $seconds,
        private int|Learned|Absent $max,
        private Seconds|Absent $target,
        private Seconds|Absent $setup,
        private Costs $costs,
        private LearnedStatistic|Absent $learnedFrom,
        private Planning $planning,
        private Outbreak $outbreak,
        private Seconds|Absent $jobLimit,
        private Pulling $pulling,
        private Seconds|Absent $progress,
        private Seconds|Absent $margin,
        private ShardTail|Absent $tail,
    ) {
    }

    /** `shards.setup`: each shard's CI setup before the gate starts, which the gate cannot time (ADR-0013). */
    public function setup(): Seconds
    {
        return $this->setup instanceof Seconds ? $this->setup : Seconds::minutes(self::SETUP_MINUTES);
    }

    public static function of(
        Seconds|Absent $seconds = new Absent(),
        int|Learned|Absent $max = new Absent(),
        Seconds|Absent $target = new Absent(),
        Seconds|Absent $setup = new Absent(),
        Table|Absent $secondsPerLine = new Absent(),
        Price|Absent $perRunnerMinute = new Absent(),
        LearnedStatistic|Absent $learnedFrom = new Absent(),
        Seconds|Absent $savingsWindow = new Absent(),
        Choice|Absent $costModel = new Absent(),
        Planning|Absent $planning = new Absent(),
        Outbreak|Absent $outbreak = new Absent(),
        Seconds|Absent $jobLimit = new Absent(),
        int|Absent $testsBeforeAKill = new Absent(),
        int|float|Absent $surviving = new Absent(),
        int|float|Absent $shardOverhead = new Absent(),
        Pulling|Absent $pulling = new Absent(),
        Seconds|Absent $progress = new Absent(),
        Seconds|Absent $margin = new Absent(),
        CostMode|Absent $costMode = new Absent(),
        ShardTail|Absent $tail = new Absent(),
    ): self {
        return new self(
            $seconds,
            $max,
            $target,
            $setup,
            Costs::of(
                $secondsPerLine,
                $perRunnerMinute,
                $savingsWindow,
                $costModel,
                $testsBeforeAKill,
                $surviving,
                $shardOverhead,
                $costMode,
            ),
            $learnedFrom,
            $planning instanceof Planning ? $planning : Planning::of(),
            $outbreak instanceof Outbreak ? $outbreak : Outbreak::of(),
            $jobLimit,
            $pulling instanceof Pulling ? $pulling : Pulling::of(),
            $progress,
            $margin,
            $tail,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return new self(
            $none->seconds(),
            $none->max(),
            new Absent(),
            $none->setup(),
            Costs::standard(),
            LearnedStatistic::Max,
            Planning::standard(),
            Outbreak::of(),
            new Absent(),
            Pulling::of($none->pulling()->pull()),
            $none->progress(),
            $none->stop()->margin(),
            $none->tail(),
        );
    }

    /**
     * Where a later layer sets one of `target` and `seconds`, it replaces the other. A part that sets both keeps
     * both, for the definition to refuse.
     */
    public function over(Part $later): self
    {
        if (! $later instanceof self) {
            return $this;
        }

        $cutByTarget = ! $later->target instanceof Absent && $later->seconds instanceof Absent;
        $cutBySeconds = ! $later->seconds instanceof Absent && $later->target instanceof Absent;

        return new self(
            $cutByTarget ? new Absent() : Absent::laid($this->seconds, $later->seconds),
            Absent::laid($this->max, $later->max),
            $cutBySeconds ? new Absent() : Absent::laid($this->target, $later->target),
            Absent::laid($this->setup, $later->setup),
            $this->costs->over($later->costs),
            Absent::laid($this->learnedFrom, $later->learnedFrom),
            $this->planning->over($later->planning),
            $this->outbreak->over($later->outbreak),
            Absent::laid($this->jobLimit, $later->jobLimit),
            $this->pulling->over($later->pulling),
            Absent::laid($this->progress, $later->progress),
            Absent::laid($this->margin, $later->margin),
            Absent::laid($this->tail, $later->tail),
        );
    }

    /** How long one shard is cut to take. */
    public function seconds(): Seconds
    {
        return $this->seconds instanceof Seconds ? $this->seconds : Seconds::of(self::SECONDS);
    }

    /** The most shards a plan cuts; where it is learned, what it is before any run was learned. */
    public function max(): int
    {
        return is_int($this->max) ? $this->max : self::MOST;
    }

    /**
     * Where `shards.max` is learned, which of the shard counts recent runs
     * ran at once it takes, `shards.learnedFrom`; none where it is written.
     */
    public function learnedFrom(): LearnedStatistic|NotGiven
    {
        return match (true) {
            ! $this->max instanceof Learned => NotGiven::value(),
            $this->learnedFrom instanceof LearnedStatistic => $this->learnedFrom,
            default => LearnedStatistic::Max,
        };
    }

    /** `costs`: what the gate's work costs and how it says so. */
    public function costs(): Costs
    {
        return $this->costs;
    }

    /** `plan`, `timing` and `shards.cutAt`: how a run plans its work and learns what it costs. */
    public function planning(): Planning
    {
        return $this->planning;
    }

    /** `outbreak`: whether Outbreak's core writes and vouches for the maps a plan hands its shards (ADR-0118). */
    public function outbreak(): Outbreak
    {
        return $this->outbreak;
    }

    /** `shards.pull` (ADR-0134). */
    public function pulling(): Pulling
    {
        return $this->pulling;
    }

    /** When a job's gate stops: `shards.target`, `shards.margin` and `shards.jobLimit` (ADR-0196). */
    public function stop(): JobStop
    {
        $target = $this->target();

        return new JobStop(
            $target instanceof Seconds ? $target : $this->costs->mode()->target(),
            $this->margin instanceof Seconds ? $this->margin : Seconds::of(self::MARGIN_SECONDS),
            $this->jobLimit,
            limitAlone: $target instanceof Absent && $this->jobLimit instanceof Seconds,
        );
    }

    /**
     * `shards.tail`: whether a job stops at its target and hands on what it
     * left, or runs its own tail to its job's limit; `auto` where unset
     * (ADR-0303).
     */
    public function tail(): ShardTail
    {
        return $this->tail instanceof ShardTail ? $this->tail : ShardTail::Auto;
    }

    /**
     * `shards.progress`: how long a CI shard waits between two artifacts that
     * say how far it got, so a job lost with its runner still names its last
     * work; none at `0s` (ADR-0157).
     */
    public function progress(): Seconds
    {
        return $this->progress instanceof Seconds ? $this->progress : Seconds::minutes(self::PROGRESS_MINUTES);
    }

    /**
     * `shards.target`: the wall time the count is cut to fit, in place of
     * `shards.seconds` (ADR-0013); where neither is set and `costs.mode` is
     * `minutes`, the target that mode cuts to (ADR-0240).
     */
    public function target(): Seconds|Absent
    {
        $mode = $this->costs->mode();

        return $this->target instanceof Absent && $this->seconds instanceof Absent && $mode === CostMode::Minutes
            ? $mode->target()
            : $this->target;
    }

    public function written(PathOrigin $origin): Json
    {
        return $this->planning->written(Json::object(
            Member::unlessEmpty(
                'shards',
                Json::object(
                    Member::of('seconds', WholeSeconds::written($this->seconds)),
                    Member::of('max', LimitCalls::maxWritten($this->max)),
                    Member::of('learnedFrom', LimitCalls::statisticWritten($this->learnedFrom)),
                    Member::of('target', $this->durationWritten($this->target)),
                    Member::of('setup', $this->durationWritten($this->setup)),
                    $this->planning->cutAtWritten(),
                    Member::of(
                        'jobLimit',
                        $this->durationWritten($this->jobLimit),
                    ),
                    $this->pulling->written(),
                    Member::of('progress', $this->durationWritten($this->progress)),
                    Member::of('margin', $this->durationWritten($this->margin)),
                    Member::of('tail', $this->tail instanceof ShardTail ? $this->tail->value : $this->tail),
                ),
            ),
            $this->costs->written($origin),
        ))->merged($this->outbreak->written());
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $settings = [
            ...$this->seconds instanceof Seconds
                ? [sprintf('Shards::seconds(%d)', intval($this->seconds->seconds()))]
                : [],
            ...LimitCalls::max($this->max, $this->learnedFrom),
            ...$this->target instanceof Seconds
                ? [sprintf('Shards::target(%s)', PhpCalls::literal($this->target->written()))]
                : [],
            ...$this->setup instanceof Seconds
                ? [sprintf('Shards::setup(%s)', PhpCalls::literal($this->setup->written()))]
                : [],
            ...$this->jobLimit instanceof Seconds
                ? [sprintf('Shards::jobLimit(%s)', PhpCalls::literal($this->jobLimit->written()))]
                : [],
            ...$this->progress instanceof Seconds
                ? [sprintf('Shards::progress(%s)', PhpCalls::literal($this->progress->written()))]
                : [],
            ...$this->margin instanceof Seconds
                ? [sprintf('Shards::margin(%s)', PhpCalls::literal($this->margin->written()))]
                : [],
            ...$this->tail instanceof ShardTail
                ? [sprintf('Shards::tail(%s)', PhpCalls::literal($this->tail->value))]
                : [],
        ];

        return PhpCalls::inWith(...$settings)
            ->and($this->costs->php($origin))
            ->and($this->planning->php())
            ->and($this->pulling->php())
            ->and($this->outbreak->php());
    }

    /** A duration as the config writes it, or none where it is not set. */
    private function durationWritten(Seconds|Absent $duration): string|Absent
    {
        return $duration instanceof Seconds ? $duration->written() : $duration;
    }
}
