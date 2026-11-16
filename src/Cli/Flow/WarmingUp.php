<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Written;

use function pcntl_waitpid;

/**
 * The analyser's warm-up the plan began (see PlannedWarmUp): running in a
 * child of the plan's, or made already.
 *
 * @internal
 */
final readonly class WarmingUp
{
    /** @param Closure(): (Written|Missing|CannotJudge) $ending what the warm-up left, once it ended */
    private function __construct(private Closure $ending)
    {
    }

    /** A warm-up running in this child, which leaves how long it took in this file of the project, or none. */
    public static function in(int $child, Directory $project, Path $left): self
    {
        return new self(static function () use ($child, $project, $left): Written|Missing {
            pcntl_waitpid($child, $status);

            return $project->read($left) instanceof Contents ? Written::to($left->value()) : Missing::at($left);
        });
    }

    /** A warm-up made already, and what it left. */
    public static function ended(Written|Missing|CannotJudge $left): self
    {
        return new self(static fn(): Written|Missing|CannotJudge => $left);
    }

    /** What the warm-up left, once it ended. */
    public function left(): Written|Missing|CannotJudge
    {
        return ($this->ending)();
    }
}
