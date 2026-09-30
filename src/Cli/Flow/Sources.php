<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

/**
 * The source of each file a survivor that asks for a test is in, read once
 * from the project, for the clusters its survivors form (ADR-0022, decision
 * 15). A file that cannot be read is left out, and clusters nothing.
 */
final readonly class Sources
{
    /** @return ByPath<Contents> */
    public static function ofSurvivors(TreeVerdicts $verdicts, Directory $project): ByPath
    {
        $sources = ByPath::none();
        $read = [];

        foreach ($verdicts as $tree) {
            foreach ($tree->survivors() as $survivor) {
                $file = $survivor->mutant()->location()->file();
                $asked = $survivor->judgement()->asksForATest() && ! array_key_exists($file->value(), $read);
                $contents = $asked ? $project->read($file) : Missing::at($file);
                $read[$file->value()] = true;
                $sources = $contents instanceof Contents ? $sources->with($file, $contents) : $sources;
            }
        }

        return $sources;
    }

    /**
     * Each of these files the project holds, such as the test files whose
     * assertions are read (ADR-0025, decision 5). A file that cannot be read
     * is left out.
     *
     * @return ByPath<Contents>
     */
    public static function of(Paths $files, Directory $project): ByPath
    {
        $sources = ByPath::none();

        foreach ($files as $file) {
            $contents = $project->read($file);
            $sources = $contents instanceof Contents ? $sources->with($file, $contents) : $sources;
        }

        return $sources;
    }
}
