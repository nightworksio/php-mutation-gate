<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How the streams of a split-stream run get through their work (ADR-0182):
 * the `splitStream` keys that move only how fast a run judges, never what
 * it finds.
 */
final readonly class StreamWork
{
    /**
     * The key, and the Pest runner's option, that says whether a stream is stopped, and started again on its other
     * tests, once its parent runs a test past that test's deadline (ADR-0182).
     */
    public const string PARENT_DEADLINE = 'parentDeadline';

    public function __construct(private bool|Absent $parentDeadline = new Absent())
    {
    }

    /** Every key at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        return new self(new self()->parentDeadline());
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->parentDeadline, $later->parentDeadline));
    }

    /**
     * `splitStream.parentDeadline`: whether a stream whose parent runs a
     * test past the limit a child of it is held to, of the map's time of the
     * test, is stopped with every process under it and started again on the
     * tests it had not ended (ADR-0182); on by default.
     */
    public function parentDeadline(): bool
    {
        return $this->parentDeadline !== false;
    }

    /**
     * The members of the Pest runner's `splitStream` option: every key at the value it takes.
     *
     * @return list<Member>
     */
    public function option(): array
    {
        return [Member::of(self::PARENT_DEADLINE, $this->parentDeadline())];
    }

    /**
     * The members of the `splitStream` member a config writes, each key it sets.
     *
     * @return list<Member>
     */
    public function written(): array
    {
        return [Member::of(self::PARENT_DEADLINE, $this->parentDeadline)];
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::flag($this->parentDeadline, 'StreamWork::parentDeadline()', 'StreamWork::noParentDeadline()');
    }
}
