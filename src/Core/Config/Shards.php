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
 * ADR-0013, ADR-0016): `shards` and `costs`.
 */
final readonly class Shards implements Part
{
    /** The seconds of work one shard is cut to, where the config does not say. */
    private const int SECONDS = 600;

    /** The most shards a plan cuts, where the config does not say. */
    private const int MOST = 20;

    /** The minutes each shard's CI setup takes before the gate starts, where the config does not say. */
    private const int SETUP_MINUTES = 1;

    private function __construct(
        private Seconds|Absent $seconds,
        private int|Learned|Absent $max,
        private Seconds|Absent $target,
        private Seconds|Absent $setup,
        private Costs $costs,
        private LearnedStatistic|Absent $learnedFrom,
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
    ): self {
        return new self(
            $seconds,
            $max,
            $target,
            $setup,
            Costs::of($secondsPerLine, $perRunnerMinute, $savingsWindow, $costModel),
            $learnedFrom,
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
            new Absent(),
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

    /** `shards.target`: the wall time the count is cut to fit, in place of `shards.seconds` (ADR-0013). */
    public function target(): Seconds|Absent
    {
        return $this->target;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'shards',
                Json::object(
                    Member::of('seconds', WholeSeconds::written($this->seconds)),
                    Member::of('max', LimitCalls::maxWritten($this->max)),
                    Member::of('learnedFrom', LimitCalls::statisticWritten($this->learnedFrom)),
                    Member::of('target', $this->target instanceof Seconds ? $this->target->written() : $this->target),
                    Member::of('setup', $this->setup instanceof Seconds ? $this->setup->written() : $this->setup),
                ),
            ),
            $this->costs->written($origin),
        );
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
        ];

        return PhpCalls::inWith(...$settings)->and($this->costs->php($origin));
    }
}
