<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Report\NoTrend;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * What a verdict on the default branch alerts, against the newest entry of
 * the trend the flows handed it (ADR-0016, decision 10):
 * - *failed* when it failed and the entry did not, or there is no entry;
 * - *cannot judge* when it cannot judge and the entry judged;
 * - *recovered* when it passed and the entry failed or could not judge;
 * - *floor lowered* when a tree's floor is below the entry's, once.
 *
 * A verdict with no trend, off the default branch, or cut short by its
 * budget alerts nothing.
 *
 * @implements IteratorAggregate<int, Alert>
 */
final readonly class Alerts implements Countable, IteratorAggregate
{
    /** @param list<Alert> $alerts */
    private function __construct(private array $alerts)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $trend = $verdict->account()->previous();

        if ($trend instanceof NoTrend || $verdict->wasCutShort()) {
            return new self([]);
        }

        $previous = $trend->newest();
        $event = self::change($verdict->judgement(), $previous->verdict());
        $events = [
            ...$event instanceof AlertEvent ? [$event] : [],
            ...count(LoweredFloors::between($verdict, $previous)) > 0 ? [AlertEvent::FloorLowered] : [],
        ];
        $alerts = [];

        foreach ($events as $each) {
            $alerts[] = Alert::of($each, $verdict, $previous);
        }

        return new self($alerts);
    }

    public function count(): int
    {
        return count($this->alerts);
    }

    /** @return Traversable<int, Alert> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->alerts);
    }

    /** How the state changed from the entry's judgement to this one. */
    private static function change(Judgement $now, Judgement|Unrecorded $before): AlertEvent|Steady
    {
        $judged = $before instanceof Judgement;
        $unsettled = $before === Judgement::Failed || $before === Judgement::CannotJudge;

        return match (true) {
            $now === Judgement::Failed && $before !== Judgement::Failed => AlertEvent::Failed,
            $now === Judgement::CannotJudge && $judged && $before !== Judgement::CannotJudge => AlertEvent::CannotJudge,
            $now === Judgement::Passed && $unsettled => AlertEvent::Recovered,
            default => Steady::state(),
        };
    }
}
