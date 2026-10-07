<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The next runner invocation of a run that goes in batches: a budgeted run
 * (ADR-0008, decision 1), and a pull request's shard that stops once its
 * verdict cannot pass (decision 6). Units are taken in the order given: a
 * held unit alone, against the tests that hold it, and otherwise as many
 * units in a row as fit the time left and the chunk, since every invocation
 * pays the runner's opening run. Where the first unit does not fit the time
 * left, nothing is started; a chunk always starts with its first unit.
 */
final readonly class Batching
{
    /** How much expected work a chunk of a shard that can stop once it cannot pass holds. */
    private const int CHUNK_MINUTES = 2;

    private function __construct(private Seconds $opening, private Seconds|Unlimited $chunk)
    {
    }

    /** Batches whose every invocation opens with a run that takes this long. */
    public static function opening(Seconds $opening): self
    {
        return new self($opening, Unlimited::time());
    }

    /** The chunk a shard that can stop once it cannot pass runs its units in (ADR-0008, decision 6). */
    public static function chunk(): Seconds
    {
        return Seconds::minutes(self::CHUNK_MINUTES);
    }

    /** These batches, each ending once the next unit takes it, opening run and all, past this. */
    public function inChunksOf(Seconds $chunk): self
    {
        return new self($this->opening, $chunk);
    }

    /**
     * The units of the next invocation, none where the first does not fit.
     *
     * @param list<Weighed> $ordered the units left, in the order they are taken, each with what it costs
     */
    public function next(array $ordered, Seconds|Unlimited $left): Units
    {
        $batch = Units::none();
        $spent = $this->opening->seconds();

        foreach ($ordered as $weighed) {
            $unit = $weighed->unit();
            $spent += $weighed->cost()->seconds();
            $full = $batch->count() > 0 && ($unit->isHeld() || $this->past($spent, $this->chunk));

            if ($full || $this->past($spent, $left)) {
                break;
            }

            $batch = $batch->with($unit);

            if ($unit->isHeld()) {
                break;
            }
        }

        return $batch;
    }

    private function past(float $spent, Seconds|Unlimited $limit): bool
    {
        return $limit instanceof Seconds && $spent > $limit->seconds();
    }
}
