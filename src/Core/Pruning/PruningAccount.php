<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function count;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * What pruning did in a run, for every report (ADR-0025, decision 4): the
 * mutators it pruned, the window each was judged over and the last mutant
 * each let through, where one ever did; how many units it pruned them in;
 * how many mutants it carried from runs of the last `pruning.audit`; and the
 * time those mutants took when they ran, which pruning saved.
 */
final readonly class PruningAccount
{
    private function __construct(
        private MutatorNames $mutators,
        private Survival $survival,
        private Name $runner,
        private Window $window,
        private int $units,
        private int $carried,
        private Seconds $audit,
        private Seconds $saved,
    ) {
    }

    /** A run that pruned nothing. */
    public static function none(): self
    {
        return new self(
            MutatorNames::none(),
            Survival::none(),
            Name::of(''),
            Window::of(0),
            0,
            0,
            Seconds::of(0.0),
            Seconds::of(0.0),
        );
    }

    /**
     * What a run pruned, by what the ledgers it read learned of the runner's
     * mutators, and what it carried into the results it judged.
     */
    public static function of(
        Pruned $pruned,
        Survival $survival,
        Name $runner,
        Window $window,
        Seconds $audit,
        UnitResults $judged,
    ): self {
        $units = 0;
        $carried = 0;
        $saved = 0.0;

        foreach ($judged as $result) {
            $units += count($result->carriedPruned()) > 0 ? 1 : 0;
            $carried += count($result->carriedPruned());
            $saved += self::took($result);
        }

        return new self(
            $pruned->mutators(),
            $survival,
            $runner,
            $window,
            $units,
            $carried,
            $audit,
            Seconds::of($saved),
        );
    }

    /** Whether the run pruned anything. */
    public function isNone(): bool
    {
        return count($this->mutators) === 0;
    }

    /** The mutators it pruned, by the runner's names for them. */
    public function mutators(): MutatorNames
    {
        return $this->mutators;
    }

    /** How many of a mutator's newest judged mutants it was pruned over. */
    public function window(): Window
    {
        return $this->window;
    }

    /** The id of the last mutant a pruned mutator let through; none where it never let one through. */
    public function lastOf(RunnerMutatorName $mutator): string|NotGiven
    {
        return $this->survival->of($this->runner, $mutator)->last();
    }

    /** How many units it carried a pruned mutator's results into. */
    public function units(): int
    {
        return $this->units;
    }

    /** How many mutants it carried. */
    public function carried(): int
    {
        return $this->carried;
    }

    /** How old a result it carried may be: `pruning.audit`. */
    public function audit(): Seconds
    {
        return $this->audit;
    }

    /** The time the mutants it carried took when they last ran, which pruning saved. */
    public function saved(): Seconds
    {
        return $this->saved;
    }

    /** The seconds the mutants a result carries for a pruned mutator took when they last ran. */
    private static function took(UnitResult $result): float
    {
        $seconds = 0.0;

        foreach ($result->mutants() as $mutant) {
            $took = $mutant->duration();
            $carried = $result->carriedPruned()->has($mutant->id());
            $seconds += $carried && $took instanceof Seconds ? $took->seconds() : 0.0;
        }

        return $seconds;
    }
}
