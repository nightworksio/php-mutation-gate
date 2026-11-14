<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\PlacedMutant;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

/**
 * A project as the native runner's classes serve a mutant in it: the
 * override, each mutant's files and the files beside them, in the runner's
 * own directory of the gate's.
 *
 * @internal
 */
final readonly class Placement implements MutantPlacement
{
    public function __construct(private Project $project)
    {
    }

    /** The project at a root, with these tests, vendor and gate directories, as the Pest runner serves it. */
    public static function inPestRunner(string $root, Paths $tests, Path $vendor, Path $workspace): self
    {
        return new self(Project::at($root, $tests, $vendor, $workspace, BuiltinRunner::Pest));
    }

    public function override(OwnArchive|NotGiven $archive): string|CannotJudge
    {
        return Override::writtenFor($this->project, $archive);
    }

    public function startingPhp(string $override): array
    {
        return Invocation::overriding($override);
    }

    public function placed(Mutant $mutant, Contents $code, Selection $selection): PlacedMutant|CannotJudge
    {
        $made = MadeMutant::of($mutant->id(), $mutant->location(), $mutant->mutation(), $code);

        return MutantFiles::selecting($this->project, $made, $selection);
    }

    public function fresh(string $name): string|CannotJudge
    {
        return $this->project->fresh($name);
    }

    public function written(string $name, string $contents): string|CannotJudge
    {
        return $this->project->written($name, $contents);
    }
}
