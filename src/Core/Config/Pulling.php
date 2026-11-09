<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How shards find their work and who computes the verdict (ADR-0134):
 * `shards.pull` and `shards.lastJudges`. Neither is in a key.
 *
 * @internal
 */
final readonly class Pulling
{
    public const string PULL = 'pull';

    public const string LAST_JUDGES = 'lastJudges';

    private function __construct(private ShardPull|Absent $pull, private bool|Absent $lastJudges)
    {
    }

    public static function of(ShardPull|Absent $pull = new Absent(), bool|Absent $lastJudges = new Absent()): self
    {
        return new self($pull, $lastJudges);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->pull, $later->pull),
            Absent::laid($this->lastJudges, $later->lastJudges),
        );
    }

    /** `shards.pull`: `auto` where no layer says. */
    public function pull(): ShardPull
    {
        return $this->pull instanceof ShardPull ? $this->pull : ShardPull::Auto;
    }

    /** `shards.lastJudges`: whether the shard that finishes the last item computes the verdict; off by default. */
    public function lastJudges(): bool
    {
        return $this->lastJudges === true;
    }

    /** @return list<Member> the keys a layer sets, as `shards` writes them */
    public function members(): array
    {
        return [
            Member::of(self::PULL, $this->pull instanceof ShardPull ? $this->pull->value : $this->pull),
            Member::of(self::LAST_JUDGES, $this->lastJudges),
        ];
    }

    /** @return list<string> the builder's calls for the keys a layer sets */
    public function php(): array
    {
        return [
            ...$this->pull instanceof ShardPull ? [$this->pull->php()] : [],
            ...match ($this->lastJudges) {
                true => ['Shards::lastJudging()'],
                false => ['Shards::verdictJobJudging()'],
                default => [],
            },
        ];
    }
}
