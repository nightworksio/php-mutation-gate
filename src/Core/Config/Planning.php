<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\ShardSizing;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\TimingWeight;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How a run plans its work and learns what it costs: `plan`, `timing` and `shards.cutAt`, which the `Shards` part
 * holds.
 */
final readonly class Planning
{
    private function __construct(
        private int|Absent $chunkMinutes,
        private int|Absent $provingRange,
        private float|int|Absent $cutAt,
        private float|int|Absent $newest,
        private int|Absent $startUpSamples,
    ) {
    }

    public static function of(
        int|Absent $chunkMinutes = new Absent(),
        int|Absent $provingRange = new Absent(),
        float|int|Absent $cutAt = new Absent(),
        float|int|Absent $newest = new Absent(),
        int|Absent $startUpSamples = new Absent(),
    ): self {
        return new self($chunkMinutes, $provingRange, $cutAt, $newest, $startUpSamples);
    }

    /** Every key at the value it takes where every layer leaves it out. */
    public static function standard(): self
    {
        return new self(
            Batching::CHUNK_MINUTES,
            ProvingRange::STANDARD,
            ShardSizing::WORK_PER_OVERHEAD,
            Timing::NEWEST,
            StartUpSamples::STANDARD,
        );
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->chunkMinutes, $later->chunkMinutes),
            Absent::laid($this->provingRange, $later->provingRange),
            Absent::laid($this->cutAt, $later->cutAt),
            Absent::laid($this->newest, $later->newest),
            Absent::laid($this->startUpSamples, $later->startUpSamples),
        );
    }

    /** `plan.chunkMinutes`: how much expected work a chunk of a shard that can stop once it cannot pass holds. */
    public function chunk(): Seconds
    {
        return Seconds::minutes($this->chunkMinutes instanceof Absent ? Batching::CHUNK_MINUTES : $this->chunkMinutes);
    }

    /** `plan.provingRange`: how many commits since a base a change source asks to have proved. */
    public function provingRange(): ProvingRange
    {
        return $this->provingRange instanceof Absent
            ? ProvingRange::standard()
            : ProvingRange::of($this->provingRange);
    }

    /** `shards.cutAt`: how many times its overhead a shard's share of the cost must be at least. */
    public function cutAt(): float
    {
        return $this->cutAt instanceof Absent ? ShardSizing::WORK_PER_OVERHEAD : $this->cutAt;
    }

    /** `timing.newest`: the weight a unit's newest measurement takes in its smoothed timing. */
    public function newest(): TimingWeight
    {
        return TimingWeight::of($this->newest instanceof Absent ? Timing::NEWEST : $this->newest);
    }

    /** `timing.startUpSamples`: how many runs of no test a plan times to read the start-up. */
    public function startUpSamples(): StartUpSamples
    {
        return $this->startUpSamples instanceof Absent
            ? StartUpSamples::standard()
            : StartUpSamples::of($this->startUpSamples);
    }

    /** The `shards.cutAt` member, which the `shards` section writes beside its own. */
    public function cutAtWritten(): Member
    {
        return Member::of('cutAt', $this->cutAt);
    }

    /** The `plan` and `timing` sections; nothing of one that sets no key. */
    public function written(Json $written): Json
    {
        return $written
            ->with(Member::unlessEmpty(
                'plan',
                Json::object(
                    Member::of('chunkMinutes', $this->chunkMinutes),
                    Member::of('provingRange', $this->provingRange),
                ),
            ))
            ->with(Member::unlessEmpty(
                'timing',
                Json::object(
                    Member::of('newest', $this->newest),
                    Member::of('startUpSamples', $this->startUpSamples),
                ),
            ));
    }

    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->called('Plan::chunkMinutes(%s)', $this->chunkMinutes),
            ...$this->called('Plan::provingRange(%s)', $this->provingRange),
            ...$this->called('Shards::cutAt(%s)', $this->cutAt),
            ...$this->called('Timing::newest(%s)', $this->newest),
            ...$this->called('Timing::startUpSamples(%s)', $this->startUpSamples),
        ]);
    }

    /** @return list<string> the builder's call that sets a key, where a layer sets it */
    private function called(string $call, float|int|Absent $value): array
    {
        return $value instanceof Absent ? [] : [sprintf($call, PhpCalls::literal($value))];
    }
}
