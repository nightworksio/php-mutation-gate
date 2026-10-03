<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/** The files some mutants are in, read from the project, for a report that shows their code or columns. */
final readonly class MutatedFiles
{
    private function __construct(private Directory $project)
    {
    }

    public static function in(Directory $project): self
    {
        return new self($project);
    }

    /** Each file these mutants are in that can be read, read once. */
    public function of(Survivors|JudgedMutants $mutants): Sources
    {
        $sources = Sources::none();

        foreach ($mutants as $judged) {
            $file = $judged->mutant()->location()->file();

            if ($sources->has($file)) {
                continue;
            }

            $contents = $this->project->read($file);
            $sources = $contents instanceof Contents ? $sources->with($file, $contents) : $sources;
        }

        return $sources;
    }
}
