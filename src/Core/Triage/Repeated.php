<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Triage;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * A unit's mutants over runs of it repeated as `triage` repeats them
 * (ADR-0008, decision 3), each run's mutants by their gate ids. A mutant
 * varied where its runs did not all give one status, or some runs did not
 * make it; which tests killed it may differ without its varying.
 */
final readonly class Repeated
{
    /** @param list<array<string, Mutant>> $runs each run's mutants, by id */
    private function __construct(private array $runs)
    {
    }

    public static function of(Mutants ...$runs): self
    {
        $byId = [];

        foreach ($runs as $mutants) {
            $run = [];

            foreach ($mutants as $mutant) {
                $run[$mutant->id()->value()] = $mutant;
            }

            $byId[] = $run;
        }

        return new self($byId);
    }

    public function runs(): int
    {
        return count($this->runs);
    }

    /** How many mutants any of the runs made. */
    public function mutants(): int
    {
        return count($this->firstSeen());
    }

    /** @return list<Outcomes> each mutant that varied, in the order the runs first made them */
    public function varied(): array
    {
        $varied = [];

        foreach ($this->firstSeen() as $id => $mutant) {
            $outcomes = [];

            foreach ($this->runs as $run) {
                $outcomes[] = array_key_exists($id, $run) ? $run[$id] : NotMade::inThatRun();
            }

            $one = Outcomes::over($mutant, $outcomes);
            $varied = $one->didVary() ? [...$varied, $one] : $varied;
        }

        return $varied;
    }

    /** @return array<string, Mutant> each mutant any run made, as the first run that made it reported it */
    private function firstSeen(): array
    {
        $seen = [];

        foreach ($this->runs as $run) {
            $seen += $run;
        }

        return $seen;
    }
}
