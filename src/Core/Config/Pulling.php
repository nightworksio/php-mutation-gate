<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How shards find their work (ADR-0134): `shards.pull`, which is in no key.
 *
 * @internal
 */
final readonly class Pulling
{
    public const string PULL = 'pull';

    private function __construct(private ShardPull|Absent $pull)
    {
    }

    public static function of(ShardPull|Absent $pull = new Absent()): self
    {
        return new self($pull);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->pull, $later->pull));
    }

    /** `shards.pull`: `auto` where no layer says. */
    public function pull(): ShardPull
    {
        return $this->pull instanceof ShardPull ? $this->pull : ShardPull::Auto;
    }

    /** @return list<Member> the keys a layer sets, as `shards` writes them */
    public function members(): array
    {
        return [Member::of(self::PULL, $this->pull instanceof ShardPull ? $this->pull->value : $this->pull)];
    }

    /** @return list<string> the builder's calls for the keys a layer sets */
    public function php(): array
    {
        return $this->pull instanceof ShardPull ? [$this->pull->php()] : [];
    }
}
