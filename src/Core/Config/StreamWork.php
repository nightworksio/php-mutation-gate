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

    public function __construct(
        private bool|Absent $parentDeadline = new Absent(),
        private Multiple|Absent $deadlineBackoff = new Absent(),
    ) {
    }

    /** Every key at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        $none = new self();

        return new self($none->parentDeadline(), $none->deadlineBackoff());
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->parentDeadline instanceof Absent ? $this->parentDeadline : $later->parentDeadline,
            $later->deadlineBackoff instanceof Absent ? $this->deadlineBackoff : $later->deadlineBackoff,
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

    /** The keys of the Pest runner's `splitStream` option these give: every key at the value it takes. */
    public function option(): Json
    {
        return Json::object(
            Member::of(self::PARENT_DEADLINE, $this->parentDeadline()),
            Member::of(self::DEADLINE_BACKOFF, $this->deadlineBackoff()->times()),
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
            ->and($backoff);
    }
}
