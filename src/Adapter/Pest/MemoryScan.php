<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function dirname;
use function getenv;
use function getmypid;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\CapIni;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;

use function sprintf;

/**
 * The directory of the ini file that caps a run's memory (ADR-0004, decision
 * 9), for every PHP process of the run to scan: Pest, each mutant's own
 * process, and each run of a mutant judged by reference. It is beside the
 * run's results, one for each process of the gate, so two runs in one
 * checkout never share it, and it is removed when the run is done.
 */
final readonly class MemoryScan
{
    /** Beside the results, by the id of the gate's process. */
    private const string DIRECTORY = '%s/php/%d';

    private function __construct(private DiskPath|Uncapped $directory, private CapFiles $files)
    {
    }

    /** The directory this process of the gate writes the cap of a run to, by the run's results file. */
    public static function directoryBeside(string $results): string
    {
        return sprintf(self::DIRECTORY, dirname($results), getmypid());
    }

    /** The cap written beside a run's results file, or none where it caps nothing; or why it cannot be written. */
    public static function beside(
        Project $project,
        string $results,
        MemoryCap $memory,
        CapFiles $files,
    ): self|CannotJudge {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory, $files);
        }

        $directory = DiskPath::of(self::directoryBeside($results));

        return $files->written(DiskPath::of($project->workspace()), $directory, CapIni::of($memory))
            ? new self($directory, $files)
            : CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, $directory->child(MemoryCap::FILE)->value()));
    }

    /** A command whose PHP processes scan the cap's directory too, after those they scan already. */
    public function onto(Command $command): Command
    {
        return $this->directory instanceof Uncapped
            ? $command
            : $command->with([
                MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $this->directory->value()),
            ]);
    }

    /** Removes the cap's directory once the run that scans it is done; a run with no cap has none. */
    public function remove(): void
    {
        if ($this->directory instanceof DiskPath) {
            $this->files->removed($this->directory);
        }
    }
}
