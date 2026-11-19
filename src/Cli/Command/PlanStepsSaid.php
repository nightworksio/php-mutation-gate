<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Console\Indented;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\Cost\PlanLaps;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Plan\PlanStepsRecord;
use NightWorksIO\MutationGate\Core\Written;

use function round;
use function sprintf;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * How long each step of the plan took, said on standard error and recorded
 * beside the plan, under `timing.planSteps` (ADR-0220).
 *
 * @internal
 */
final readonly class PlanStepsSaid
{
    private const string TOOK = 'The plan took %.1f s, in these steps, each that took less than 0.1 s left out:';


    private const string STEP = '%-16s %7.1f s';

    /** Where the plan records its steps, beside the plan. */
    private const string FILE = '%s/plan-steps.json';

    /**
     * The exit code of a plan that said and recorded its steps, or that
     * `timing.planSteps` keeps from both; or why they could not be recorded.
     */
    public static function of(Planning $planning, Directory $project, PlanLaps $laps, OutputInterface $aside): int
    {
        if (! $planning->planSteps()) {
            return ExitCode::Passed->value;
        }

        $file = self::file();
        $written = $project->write($file, Contents::of(PlanStepsRecord::of($laps)->printed()));

        if ($written instanceof CannotJudge) {
            return Failed::because($aside, $written);
        }

        $lines = [];

        foreach ($laps as $lap) {
            if (round($lap->took()->seconds(), 1) > 0.0) {
                $lines[] = sprintf(self::STEP, $lap->step()->value, $lap->took()->seconds());
            }
        }

        $aside->writeln(sprintf(self::TOOK, $laps->whole()->seconds()), OutputInterface::OUTPUT_RAW);

        foreach (Indented::lines($lines) as $line) {
            $aside->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $aside->writeln(Written::to($file->value())->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    public static function file(): Path
    {
        return Path::of(sprintf(self::FILE, Workspace::root()->value()));
    }
}
