<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a shard's own runs cost (ADR-0117): where its streams had both
 * passes before its job stopped, a mutant whose own run never finished is
 * one no stream judged, as a test that holds a file, a pipe or a process of
 * its own lets no stream fork in it. Such a run is of the mutant's covering
 * tests in a process of its own, whatever a pass of streams took: priced at
 * the wall seconds each own run the job finished took, as the job measured
 * them, and never below its own covering tests' seconds, which a mutant
 * its tests do not kill runs whole.
 *
 * @internal
 */
final readonly class StreamedOut
{
    private function __construct(private bool $began, private float $each)
    {
    }

    /**
     * What the job of this result measured of its own runs, these the
     * mutants it holds: the seconds its own runs took, from its second
     * pass's end, over those that finished, which are those it started less
     * those it left unrun.
     *
     * @param list<Mutant> $mutants
     */
    public static function of(ShardResult $result, array $mutants): self
    {
        $unrun = count(array_filter($mutants, self::unrun(...)));

        foreach ($result->measured()->steps() as $step) {
            if ($step->step() === Step::OwnRuns) {
                return new self(began: true, each: self::eachOf($step->took()->seconds(), $step->count() - $unrun));
            }
        }

        return new self(began: false, each: 0.0);
    }

    /**
     * The process seconds a left mutant's own run is priced at in a job of
     * this wall, its covering tests taking these seconds: none where it is
     * no own run, as where the job's streams never came to their end.
     */
    public function priceOf(Mutant $mutant, float $covering, StreamWall $wall): float|NotGiven
    {
        if (! $this->began || ! self::unrun($mutant)) {
            return NotGiven::value();
        }

        return max($wall->worth($this->each), $covering);
    }

    /** The seconds each of this many finished runs took of these; none where none finished. */
    private static function eachOf(float $seconds, int $finished): float
    {
        return $finished > 0 ? $seconds / $finished : 0.0;
    }

    /** Whether the job stopped before the mutant's own run finished. */
    private static function unrun(Mutant $mutant): bool
    {
        $reason = $mutant->reason();

        return $reason instanceof Reason && $reason->outOfTime() === OutOfTime::BeforeRunning;
    }
}
