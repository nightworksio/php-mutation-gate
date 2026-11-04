<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The project's name graph, read from the files on disk: every PHP file of
 * the suite's sources and outside its test directories, each with the files
 * it names. A carried kill follows a change through it (ADR-0008), a
 * check finds a mutant's dependents in it (ADR-0020), and a unit's key
 * reads the files it reaches through it (ADR-0007), read once for a plan
 * ({@see SuiteNames}).
 *
 * @internal
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
            default => Suite::read($trees, $files, $this->adapters->project, $this->adapters->suite),
        };

        return $suite instanceof Suite ? $this->of($suite) : $suite;
    }

    /** The graph of a suite's sources and every PHP file outside its test directories, or why one cannot be read. */
    public function of(Suite $suite): NamedFiles|CannotJudge
    {
        return SuiteNames::graphOf($this->named($suite));
    }

    /**
     * The graph of a suite's sources and every PHP file outside its test
     * directories, each file read once, or why one cannot be read.
     */
    public function named(Suite $suite): SuiteNames|CannotJudge
    {
        $outside = $this->outside($suite);

        if ($outside instanceof CannotJudge) {
            return $outside;
        }

        $read = array_map(PhpFile::read(...), $outside);

        return SuiteNames::of($this->graphOf($suite, $read), $outside, $read);
    }

    /**
     * The graph of a suite's sources and these PHP files outside its test directories, each read.
     *
     * @param array<string, PhpFile> $outside
     */
    private function graphOf(Suite $suite, array $outside): NamedFiles
    {
        $files = [];

        foreach ($suite->sources()->php() as $path => $php) {
            $files[$path->value()] = $php;
        }

        return NamedFiles::read($files + $outside);
    }

    /**
     * Every PHP file outside a suite's test directories, by its path, as it is on disk; or why one cannot be read.
     *
     * @return array<string, Contents>|CannotJudge
     */
    private function outside(Suite $suite): array|CannotJudge
    {
        $files = [];

        foreach ($suite->outside() as $file) {
            if (! $file->path()->isPhp()) {
                continue;
            }

            $contents = $this->adapters->project->read($file->path());

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $files[$file->path()->value()] = $contents;
            }
        }

        return $files;
    }
}
