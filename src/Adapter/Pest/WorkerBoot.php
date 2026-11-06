<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

/**
 * What a mutation run's warm workers boot before they fork. Where the run is
 * of the verification tail, as confirmation is, forks it, and runs every
 * test file in each mutant's own run, they boot every test file of the
 * suites the run names, as `pest.bootTail` has it (ADR-0075). Where the run
 * is of mutants' own runs and forks them, they boot the project, no test
 * file loaded and each file's schema served, as `pest.bootMutants` has it
 * (ADR-0091). They boot nothing otherwise, as ADR-0023's workers do.
 *
 * @internal
 */
final readonly class WorkerBoot
{
    private function __construct(private Project $project, private Patching $patching)
    {
    }

    public static function of(Project $project, Patching $patching): self
    {
        return new self($project, $patching);
    }

    /** What the run's workers boot, left in their directory, where they boot. */
    public function leftFor(MutationRequest $request, Command $command, bool $narrows, string $warm): void
    {
        $pool = $request->pool();
        $booting = Invocation::booting($this->project, PhpUnitOption::suitesIn($command->arguments()));

        if ($pool->isTail() && $this->patching->bootsTail() && $pool->workers()->forksTheTail() && ! $narrows) {
            $booting->leftIn($warm);
        }

        if (! $pool->isTail() && $this->patching->bootsMutants() && $pool->workers()->forksMutants()) {
            $booting->forMutants()->leftIn($warm);
        }
    }
}
