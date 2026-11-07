<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use function is_finite;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How long a chat reporter waits on its post (ADR-0016): the longest one
 * request may take, `with.timeout`, and the longest a `Retry-After` is
 * waited for before the one retry, `with.longestWait`. An alert left for
 * `deliver` keeps them.
 *
 * @internal
 */
final readonly class PostLimits
{
    /** The option of a chat reporter that says how long one request may take. */
    public const string TIMEOUT = 'timeout';

    /** The option of a chat reporter that says how long a `Retry-After` is waited for at most. */
    public const string LONGEST_WAIT = 'longestWait';

    private const string WAIT = 'a number of seconds, none negative';

    private const float STANDARD_TIMEOUT = 10.0;

    private const float STANDARD_LONGEST_WAIT = 30.0;

    private function __construct(private Seconds $timeout, private Seconds $longestWait)
    {
    }

    /** Ten seconds a request, and half a minute's wait at most. */
    public static function standard(): self
    {
        return new self(Seconds::of(self::STANDARD_TIMEOUT), Seconds::of(self::STANDARD_LONGEST_WAIT));
    }

    public static function of(Seconds $timeout, Seconds $longestWait): self
    {
        return new self($timeout, $longestWait);
    }

    public function timeout(): Seconds
    {
        return $this->timeout;
    }

    public function longestWait(): Seconds
    {
        return $this->longestWait;
    }

    /**
     * The limits an alert left for `deliver` records, as the run that left it said; the standard ones where it
     * said nothing.
     *
     * @throws NotInShape
     */
    public static function recorded(Node $alert): self
    {
        $standard = self::standard();
        $timeout = $alert->field(self::TIMEOUT);
        $wait = $alert->field(self::LONGEST_WAIT);

        return new self(
            $timeout->isPresent() ? self::secondsIn($timeout) : $standard->timeout,
            $wait->isPresent() ? self::secondsIn($wait) : $standard->longestWait,
        );
    }

    /** @return list<Member> the members of an alert's record that hold them, each in seconds */
    public function recording(): array
    {
        return [
            Member::of(self::TIMEOUT, $this->timeout->seconds()),
            Member::of(self::LONGEST_WAIT, $this->longestWait->seconds()),
        ];
    }

    /**
     * A wait a record names, which is never negative.
     *
     * @throws NotInShape
     */
    private static function secondsIn(Node $wait): Seconds
    {
        $seconds = $wait->number();

        return is_finite($seconds) && $seconds >= 0.0
            ? Seconds::of($seconds)
            : throw NotInShape::at($wait->at(), self::WAIT);
    }
}
