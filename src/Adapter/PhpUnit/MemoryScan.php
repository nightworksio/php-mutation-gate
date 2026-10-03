<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

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
 * 9), for every PHP process of each mutant's run to scan: PHPUnit, and each
 * process it runs a test in. It prints each error on the standard output, so
 * a mutant's fatal error for the cap is read wherever a project's PHP shows
 * errors nowhere. It is in the adapter's own directory of the gate's
 * workspace, one for each process of the gate, so two runs in one checkout
 * never share it, and it is removed when the run is done.
 */
final readonly class MemoryScan
{
    private function __construct(private DiskPath|Uncapped $directory, private CapFiles $files)
    {
    }

    /** The cap written into the adapter's own directory, or none where it caps nothing; or why it cannot be written. */
    public static function in(Project $project, MemoryCap $memory, CapFiles $files): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory, $files);
        }

        $directory = DiskPath::of($project->own(sprintf(MemoryCap::DIRECTORY, getmypid())));

        return $files->written($project->workspace(), $directory, CapIni::of($memory)->showingErrors())
            ? new self($directory, $files)
            : CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, $directory->child(MemoryCap::FILE)->value()));
    }

    /** A command whose PHP processes scan the cap's directory too, after those they scan already. */
    public function onto(Command $command): Command
    {
        return $this->directory instanceof DiskPath ? $command->scanning($this->directory) : $command;
    }

    /** Removes the cap's directory once the run that scans it is done; a run with no cap has none. */
    public function remove(): void
    {
        if ($this->directory instanceof DiskPath) {
            $this->files->removed($this->directory);
        }
    }
}
