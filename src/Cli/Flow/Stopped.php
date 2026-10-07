<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;

/**
 * How a run's shards stopped once it could not pass (ADR-0008, decision 6):
 * each shard that stopped on a survivor, with the units it did not run, and
 * every unit of a shard that left no result beside one, as a shard the CI
 * cancelled then leaves none. The verdict reads those units as stopped only
 * where a shard stopped; otherwise a shard that left no result cannot be
 * judged.
 */
final readonly class Stopped
{
    /**
     * @param list<array{int, Doomed, Units}> $doomed    each doomed shard's number, its survivor and the units it left
     * @param Units                           $cancelled the units of the shards that left no result beside a doomed one
     */
    private function __construct(private array $doomed, private Units $cancelled)
    {
    }

    /**
     * How these shards stopped, those that left no result among them.
     *
     * @param list<array{Shard, ShardResult, MutationResult}> $read    each shard that left a result, with it
     * @param list<Shard>                                      $missing each shard that left none
     */
    public static function among(array $read, array $missing): self
    {
        $doomed = [];

        foreach ($read as [$shard, $result]) {
            $survivor = $result->doomed();
            $doomed = $survivor instanceof Doomed
                ? [...$doomed, [$shard->id()->number(), $survivor, $result->unjudged()]]
                : $doomed;
        }

        $cancelled = Units::none();

        foreach ($missing as $shard) {
            $cancelled = $cancelled->and($shard->units());
        }

        return new self($doomed, $cancelled);
    }

    /** Whether a shard stopped once the run could not pass. */
    public function isDoomed(): bool
    {
        return $this->doomed !== [];
    }

    /** The units the doomed shards did not run, then those of the shards that left no result. */
    public function units(): Units
    {
        $units = Units::none();

        foreach ($this->doomed as [, , $left]) {
            $units = $units->and($left);
        }

        return $units->and($this->cancelled);
    }

    /** The survivor the first doomed shard stopped on, which makes the run certain to fail; none where none stopped. */
    public function doomed(): Doomed|Undoomed
    {
        return $this->doomed === [] ? Undoomed::run() : $this->doomed[0][1];
    }

    /** Why the verdict fails, of each shard that stopped once the run could not pass. */
    public function failures(): Failures
    {
        $failures = Failures::none();

        foreach ($this->doomed as [$shard, $survivor]) {
            $failures = $failures->with(Failure::that($survivor->said($shard)));
        }

        return $failures;
    }
}
