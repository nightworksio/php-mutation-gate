<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * The controls of the mutants whose own run did not end as a test's verdict
 * does: those out of memory (see MemoryControls) and those whose run alone
 * crashed (see CrashControls).
 *
 * @internal
 */
final readonly class AbnormalEnds
{
    private function __construct(private MemoryControls $memory, private CrashControls $crashes)
    {
    }

    public static function of(MemoryControls $memory, CrashControls $crashes): self
    {
        return new self($memory, $crashes);
    }

    /** Every control asked for, each once. */
    public function asked(): Controls
    {
        return $this->memory->asked()->and($this->crashes->asked());
    }

    /** These kinds, each control asked for here of its own kind. */
    public function asking(ControlKinds $kinds): ControlKinds
    {
        return $kinds
            ->asking(ControlKind::Memory, $this->memory->asked())
            ->asking(ControlKind::Crash, $this->crashes->asked());
    }

    /** The mutants, each out of memory and each whose run alone crashed judged by what its control found. */
    public function applied(Mutants $mutants, ControlRuns $runs, Controls $left): Mutants
    {
        return $this->crashes->applied($this->memory->applied($mutants, $runs, $left), $runs, $left);
    }
}
