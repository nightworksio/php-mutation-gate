<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;

use function sprintf;

/**
 * Pest's run of the suite under coverage, into a directory with no earlier
 * run's map, JUnit log or timed tests left there.
 *
 * @internal
 */
final readonly class CoverageRunning
{
    private const string STALE_MAP = 'An earlier run left %s or its JUnit log, and the gate cannot remove them.';

    public function __construct(private Project $project, private Shell $shell)
    {
    }

    /** A clean coverage run into a directory, or why it did not run. */
    public function into(CoverageRun $request, string $directory): Ran|CannotJudge
    {
        $map = sprintf('%s/%s', $directory, Invocation::MAP);

        $junit = sprintf('%s/%s', $directory, Invocation::JUNIT);

        if (! $this->project->without($map, $junit, sprintf('%s/%s', $directory, Invocation::TIMED))) {
            return CannotJudge::because(sprintf(self::STALE_MAP, $map));
        }

        $ran = $this->shell->run(Invocation::of($this->project)
            ->coverage($request, $directory, PcovReach::under($this->project->root(), $this->project->vendor())));

        return $ran->succeeded() ? $ran : CoverageFailure::said(Program::Pest, $ran->output());
    }
}
