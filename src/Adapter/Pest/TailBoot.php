<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

/**
 * Whether a mutation run's warm workers boot the project before they fork
 * (ADR-0075), as `pest.bootTail` has it: where the run is of the
 * verification tail, as confirmation is, forks it, and runs every test file
 * in each mutant's own run, they boot every test file of the suites the run
 * names. Mutants' own runs of an invocation keep ADR-0023's unbooted
 * workers.
 *
 * @internal
 */
final readonly class TailBoot
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

        if ($this->patching->bootsTail() && $pool->isTail() && $pool->workers()->forksTheTail() && ! $narrows) {
            Invocation::booting($this->project, PhpUnitOption::suitesIn($command->arguments()))->leftIn($warm);
        }
    }
}
