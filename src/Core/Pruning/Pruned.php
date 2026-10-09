<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;

/**
 * The mutators a run leaves out of the files whose content is unchanged
 * since their newest full result (ADR-0025, decisions 1 and 2): the runner
 * makes no mutant of those mutators in those files, and the gate carries
 * their last results instead.
 */
final readonly class Pruned
{
    private function __construct(private MutatorNames $mutators, private Paths $files)
    {
    }

    /** Nothing pruned: every mutator runs on every file. */
    public static function none(): self
    {
        return new self(MutatorNames::none(), Paths::none());
    }

    public static function of(MutatorNames $mutators, Paths $files): self
    {
        return new self($mutators, $files);
    }

    /** Whether the run leaves out this mutator's mutants of this file. */
    public function leavesOut(Path $file, RunnerMutatorName $mutator): bool
    {
        return $this->files->has($file) && $this->mutators->has($mutator);
    }

    /** Whether it prunes anything at all. */
    public function isNone(): bool
    {
        return count($this->mutators) === 0 || count($this->files) === 0;
    }

    public function mutators(): MutatorNames
    {
        return $this->mutators;
    }

    public function files(): Paths
    {
        return $this->files;
    }
}
