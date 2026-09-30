<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

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
 * 9), for every PHP process of the run to scan: Infection's own, and each
 * mutant's PHPUnit, all of which inherit the environment. It prints each
 * error on the standard output, the only output of a mutant Infection logs,
 * so a mutant's fatal error for the cap is read wherever a project's PHP
 * shows errors nowhere. It is in
 * Infection's own directory of the gate's workspace, one for each process of
 * the gate, so two runs in one checkout never share it, and it is removed
 * when the run is done.
 */
final readonly class MemoryScan
{
    /** In Infection's own directory, by the id of the gate's process. */
    private const string DIRECTORY = 'php/%d';

    private function __construct(private DiskPath|Uncapped $directory, private CapFiles $files)
    {
    }

    /** The directory this process of the gate writes the cap of a run to. */
    public static function directoryIn(Project $project): string
    {
        return $project->own(sprintf(self::DIRECTORY, getmypid()));
    }

    /** The cap written into Infection's own directory, or none where it caps nothing; or why it cannot be written. */
    public static function in(Project $project, MemoryCap $memory, CapFiles $files): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory, $files);
        }

        $directory = DiskPath::of(self::directoryIn($project));

        return $files->written(DiskPath::of($project->workspace()), $directory, CapIni::of($memory)->showingErrors())
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
