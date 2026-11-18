<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

use function sprintf;

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

    /**
     * The key, and the Pest runner's option, of the times its slowdown a stream started in a stopped one's place
     * starts at, over the stopped one's (ADR-0184).
     */
    public const string DEADLINE_BACKOFF = 'deadlineBackoff';

    /** `splitStream.deadlineBackoff` where no layer sets it: a restarted stream starts twice as slow. */
    public const float STANDARD_BACKOFF = 2.0;

    /**
     * The key, and the Pest runner's option, of where the slowdown a pass's streams measured carries on to, as
     * the next pass's start (ADR-0184).
     */
    public const string SLOWDOWN_CARRY = 'slowdownCarry';

    /**
     * The key, and the Pest runner's option, of where a first-pass stream forks a mutant the ledger names no likely
     * killer of (ADR-0187).
     */
    public const string FIRST_FORK = 'firstFork';

    public function __construct(
        private bool|Absent $parentDeadline = new Absent(),
        private Multiple|Absent $deadlineBackoff = new Absent(),
        private SlowdownCarry|Absent $slowdownCarry = new Absent(),
        private FirstFork|Absent $firstFork = new Absent(),
    ) {
    }

    /** Every key at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        $none = new self();

        return new self($none->parentDeadline(), $none->deadlineBackoff(), $none->slowdownCarry(), $none->firstFork());
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->parentDeadline instanceof Absent ? $this->parentDeadline : $later->parentDeadline,
            $later->deadlineBackoff instanceof Absent ? $this->deadlineBackoff : $later->deadlineBackoff,
            $later->slowdownCarry instanceof Absent ? $this->slowdownCarry : $later->slowdownCarry,
            $later->firstFork instanceof Absent ? $this->firstFork : $later->firstFork,
        );
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
     * `splitStream.deadlineBackoff`: the times its slowdown a stream started
     * in the place of one its parent's deadline stopped starts at, over the
     * slowdown the stopped one reached, so streams a loaded machine stops
     * stop ever less often (ADR-0184); 2 by default, at least 1.
     */
    public function deadlineBackoff(): Multiple
    {
        return $this->deadlineBackoff instanceof Absent ? Multiple::of(self::STANDARD_BACKOFF) : $this->deadlineBackoff;
    }

    /**
     * `splitStream.slowdownCarry`: where the highest slowdown a pass's
     * streams measured themselves at carries on to, as the slowdown the next
     * pass's streams start at (ADR-0184); through the shard by default, so
     * its next run starts there too.
     */
    public function slowdownCarry(): SlowdownCarry
    {
        return $this->slowdownCarry instanceof SlowdownCarry ? $this->slowdownCarry : SlowdownCarry::Shard;
    }

    /**
     * `splitStream.firstFork`: where a first-pass stream forks a mutant the
     * ledger names no likely killer of (ADR-0187); at the covering test of
     * the shard's part the map timed fastest by default.
     */
    public function firstFork(): FirstFork
    {
        return $this->firstFork instanceof FirstFork ? $this->firstFork : FirstFork::Cheapest;
    }

    /** The keys of the Pest runner's `splitStream` option these give: every key at the value it takes. */
    public function option(): Json
    {
        return Json::object(
            Member::of(self::PARENT_DEADLINE, $this->parentDeadline()),
            Member::of(self::DEADLINE_BACKOFF, $this->deadlineBackoff()->times()),
            Member::of(self::SLOWDOWN_CARRY, $this->slowdownCarry()->value),
            Member::of(self::FIRST_FORK, $this->firstFork()->value),
        );
    }

    /** The keys of the `splitStream` member a config writes of these, each key it sets. */
    public function written(): Json
    {
        return Json::object(
            Member::of(self::PARENT_DEADLINE, $this->parentDeadline),
            Member::of(
                self::DEADLINE_BACKOFF,
                $this->deadlineBackoff instanceof Absent ? $this->deadlineBackoff : $this->deadlineBackoff->times(),
            ),
            Member::of(
                self::SLOWDOWN_CARRY,
                $this->slowdownCarry instanceof SlowdownCarry ? $this->slowdownCarry->value : $this->slowdownCarry,
            ),
            Member::of(
                self::FIRST_FORK,
                $this->firstFork instanceof FirstFork ? $this->firstFork->value : $this->firstFork,
            ),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        $backoff = $this->deadlineBackoff instanceof Absent
            ? PhpCalls::none()
            : PhpCalls::inWith(sprintf(
                'StreamWork::deadlineBackoff(%s)',
                PhpCalls::literal($this->deadlineBackoff->times()),
            ));

        return PhpCalls::flag($this->parentDeadline, 'StreamWork::parentDeadline()', 'StreamWork::noParentDeadline()')
            ->and($backoff)
            ->and(PhpCalls::inWith(
                ...$this->slowdownCarry instanceof SlowdownCarry ? [$this->slowdownCarry->php()] : [],
            ))
            ->and(PhpCalls::inWith(...$this->firstFork instanceof FirstFork ? [$this->firstFork->php()] : []));
    }
}
