<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The project's name graph, read from the files on disk: every PHP file of
 * the suite's sources and outside its test directories, each with the files
 * it names. A carried kill follows a change through it (ADR-0008, decision
 * 1), and a check finds a mutant's dependents in it (ADR-0020, decision 7).
 */
final readonly class NameGraph
{
    public function __construct(private Adapters $adapters)
    {
    }

    /** The graph of the project as it is on disk, or why it cannot be read. */
    public function read(): NamedFiles|CannotJudge
    {
        $trees = $this->adapters->trees->trees();
        $files = $this->adapters->changes->fingerprints();
        $suite = match (true) {
            $trees instanceof CannotJudge => $trees,
            $files instanceof CannotTell => CannotJudge::because($files->why()),
            default => Suite::read($trees, $files, $this->adapters->project),
        };

        return $suite instanceof Suite ? $this->of($suite) : $suite;
    }

    /** The graph of a suite's sources and every PHP file outside its test directories, or why one cannot be read. */
    public function of(Suite $suite): NamedFiles|CannotJudge
    {
        $files = [];

        foreach ($suite->sources()->php() as $path => $php) {
            $files[$path->value()] = $php;
        }

        foreach ($suite->outside() as $file) {
            if (! $file->path()->isPhp()) {
                continue;
            }

            $contents = $this->adapters->project->read($file->path());

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $files += [$file->path()->value() => PhpFile::read($contents)];
            }
        }

        return NamedFiles::read($files);
    }
}
