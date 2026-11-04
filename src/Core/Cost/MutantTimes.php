<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_key_exists;
use function array_replace;
use function array_sum;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The runner time each mutant a shard ran took: the time the shard spent
 * mutating, shared among its mutants in proportion to their durations, as
 * {@see Shares} shares it among units. A mutant with no duration of its own,
 * as every mutant of Infection's is, weighs the time the tests covering its
 * line took; where nothing was timed at all, the mutants share it equally.
 */
final readonly class MutantTimes
{
    /** @param array<string, Seconds> $times by mutant id */
    private function __construct(private array $times)
    {
    }

    /** No mutant timed. */
    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Mutants $mutants, CoverageMap $coverage, Seconds $spent): self
    {
        $weights = [];

        foreach ($mutants as $mutant) {
            $weights[$mutant->id()->value()] = self::weightOf($mutant, $coverage);
        }

        $total = array_sum($weights);
        $times = [];

        foreach ($weights as $id => $weight) {
            $share = $total > 0.0 ? $weight / $total : 1 / count($weights);
            $times[$id] = Seconds::of($spent->seconds() * $share);
        }

        return new self($times);
    }

    /** How much of a shard's time a mutant weighs: its own duration, or the time the tests covering it took. */
    public static function weightOf(Mutant $mutant, CoverageMap $coverage): float
    {
        $duration = $mutant->duration();

        if ($duration instanceof Seconds) {
            return $duration->seconds();
        }

        $standIn = 0.0;
        $location = $mutant->location();

        foreach ($coverage->testsCovering($location->file(), $location->start(), $location->last()) as $test) {
            $took = $coverage->durationOf($test);
            $standIn += $took instanceof Seconds ? $took->seconds() : 0.0;
        }

        return $standIn;
    }

    /** These times, and those of another shard's mutants. */
    public function and(self $other): self
    {
        return new self(array_replace($this->times, $other->times));
    }

    /** The time a mutant took; none for one the shard did not run. */
    public function took(MutantId $mutant): Seconds|NotGiven
    {
        return array_key_exists($mutant->value(), $this->times) ? $this->times[$mutant->value()] : NotGiven::value();
    }
}
