<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_key_exists;
use function array_merge;
use function array_replace;
use function array_values;

use ArrayIterator;
use IteratorAggregate;

use function ksort;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use Traversable;

use function usort;

/**
 * What a ledger tallied of each mutator's mutants, by the runner that made
 * them and the mutator's name (ADR-0076): what each costs and what it finds.
 * Losing it loses the report, never a verdict.
 *
 * @implements IteratorAggregate<int, MutatorTally>
 */
final readonly class MutatorTallies implements IteratorAggregate
{
    /** @param array<string, array<string, MutatorTally>> $tallies by runner, then by mutator, each sorted */
    private function __construct(private array $tallies)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** This, with a mutator's tally, replacing the one held of that runner's mutator. */
    public function with(MutatorTally $tally): self
    {
        $runner = $tally->runner();
        $held = array_key_exists($runner, $this->tallies) ? $this->tallies[$runner] : [];
        $mutators = array_replace($held, [$tally->mutator() => $tally]);
        ksort($mutators);
        $all = array_replace($this->tallies, [$runner => $mutators]);
        ksort($all);

        return new self($all);
    }

    /** The tally of a runner's mutator; an empty one where nothing was tallied of it. */
    public function of(Name $runner, RunnerMutatorName $mutator): MutatorTally
    {
        $named = $runner->value();
        $name = $mutator->value();

        return array_key_exists($named, $this->tallies) && array_key_exists($name, $this->tallies[$named])
            ? $this->tallies[$named][$name]
            : MutatorTally::of($named, $name);
    }

    /**
     * This, after the mutants a runner ran, each as the verdict judged it and for the time it took: a mutant the
     * run did not time, as one it carried rather than ran, is not counted, and one the verdict holds twice is
     * counted once.
     */
    public function after(Name $runner, JudgedMutants $judged, MutantTimes $times): self
    {
        $counted = [];
        $tallied = [];

        foreach ($judged as $mutant) {
            $id = $mutant->mutant()->id()->value();
            $took = $mutant instanceof JudgedMutant ? $times->took($mutant->mutant()->id()) : NotGiven::value();

            if ($took instanceof Seconds && ! array_key_exists($id, $counted)) {
                $counted[$id] = true;
                $tallied = $this->counting($tallied, $runner, $mutant, $took);
            }
        }

        $tallies = $this;

        foreach ($tallied as $tally) {
            $tallies = $tallies->with($tally);
        }

        return $tallies;
    }

    /**
     * This, read before another ledger's: each runner's mutators as this holds them, and the other's where this
     * holds none of that mutator.
     */
    public function and(self $other): self
    {
        $tallies = $this;

        foreach ($other as $tally) {
            $mine = array_key_exists($tally->runner(), $this->tallies)
                && array_key_exists($tally->mutator(), $this->tallies[$tally->runner()]);
            $tallies = $mine ? $tallies : $tallies->with($tally);
        }

        return $tallies;
    }

    /** Only what this tallied of one runner's mutators. */
    public function under(Name $runner): self
    {
        $named = $runner->value();

        return new self(array_key_exists($named, $this->tallies) ? [$named => $this->tallies[$named]] : []);
    }

    /** The runner time every mutator's mutants took together. */
    public function seconds(): Seconds
    {
        $seconds = 0.0;

        foreach ($this as $tally) {
            $seconds += $tally->seconds()->seconds();
        }

        return Seconds::of($seconds);
    }

    /** The share of every mutator's time a mutator's mutants took; none where nothing took any. */
    public function shareOf(MutatorTally $tally): Percentage|NotGiven
    {
        $total = $this->seconds()->microseconds();
        $share = $total > 0 ? Percentage::share($tally->seconds()->microseconds(), $total) : NotGiven::value();

        return $share instanceof Percentage ? $share : NotGiven::value();
    }

    /** @return Listed<MutatorTally> every tally, the costliest first, then by runner and mutator */
    public function costliest(): Listed
    {
        $tallies = [...$this];
        usort(
            $tallies,
            static fn(MutatorTally $one, MutatorTally $other): int => self::rank($one) <=> self::rank($other),
        );

        return Listed::of(...$tallies);
    }

    /** @return Traversable<int, MutatorTally> each tally, by runner, then by mutator */
    public function getIterator(): Traversable
    {
        $all = [];

        foreach ($this->tallies as $mutators) {
            $all[] = array_values($mutators);
        }

        return new ArrayIterator(array_merge(...$all));
    }

    /** @return array{float, string, string} where a tally ranks: the costlier first, then by runner and mutator */
    private static function rank(MutatorTally $tally): array
    {
        return [-$tally->seconds()->seconds(), $tally->runner(), $tally->mutator()];
    }

    /**
     * These tallies, by mutator, with one more mutant of a runner counted, starting from what this holds of its
     * mutator.
     *
     * @param  array<string, MutatorTally> $tallied
     * @return array<string, MutatorTally>
     */
    private function counting(array $tallied, Name $runner, JudgedMutant|JudgedKill $mutant, Seconds $took): array
    {
        $mutator = $mutant->mutant()->mutator();
        $held = array_key_exists($mutator, $tallied)
            ? $tallied[$mutator]
            : $this->of($runner, RunnerMutatorName::of($mutator));

        $tallied[$mutator] = $held->after($mutant->judgement(), $took);

        return $tallied;
    }
}
