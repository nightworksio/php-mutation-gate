<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a replay of a kill's own run, unmutated, says of the kill (ADR-0004):
 * it stands only where the replay ran as many tests as the run did up to its
 * last killer, every one passed, the replay ended well, and it took them in
 * the same order. Anything else leaves the kill unjudged, saying which.
 */
enum ReplayVerdict
{
    case Stands;

    /** No time was left to start the replay, or it ran out of it. */
    case NoTime;

    /** The replay ran past the seconds Pest allowed the mutant whose kill it vouches for. */
    case OverLimit;

    /** The file the mutant changes cannot be served unmutated. */
    case Unserved;

    /** The replay ran another number of tests than the run did before its last killer. */
    case OtherCount;

    /** A test failed or errored in the replay, with the file unmutated. */
    case Failed;

    /** The replay failed with no test failing, as a run does on an issue its configuration fails it on. */
    case FailedRun;

    /** The replay took its tests in another order than the run. */
    case OtherOrder;

    private const string NO_TIME
        = 'Killed, but no time was left to replay its run unmutated, so nothing vouches for the kill.';

    private const string OVER_LIMIT
        = 'Killed, but its run, replayed unmutated, ran past the limit its mutant had, so it vouches for nothing.';

    private const string UNSERVED
        = 'Killed, but the gate cannot serve its file unmutated to replay its run, so nothing vouches for the kill.';

    private const string OTHER_COUNT
        = 'Killed, but its run, replayed unmutated, ran another number of tests up to its last killer.';

    private const string FAILED
        = 'Killed, but a test of its run fails unmutated too, replayed in its order, served as its mutant was.';

    private const string FAILED_RUN
        = 'Killed, but its run, replayed unmutated, failed with no test failing, as on an issue it fails on.';

    private const string OTHER_ORDER
        = 'Killed, but its run, replayed unmutated, took its tests in another order, so it vouches for nothing.';

    /** Why a kill this verdict does not let stand is unjudged; none for one it lets stand. */
    public function reason(): Reason|NotGiven
    {
        return match ($this) {
            self::Stands => NotGiven::value(),
            self::NoTime => Reason::that(self::NO_TIME),
            self::OverLimit => Reason::that(self::OVER_LIMIT),
            self::Unserved => Reason::that(self::UNSERVED),
            self::OtherCount => Reason::that(self::OTHER_COUNT),
            self::Failed => Reason::that(self::FAILED),
            self::FailedRun => Reason::that(self::FAILED_RUN),
            self::OtherOrder => Reason::that(self::OTHER_ORDER),
        };
    }
}
