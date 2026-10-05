<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate verdict --plan=<file> --results=<dir>`: merges every shard's
 * result with the proved and carried ones, judges the floors, and writes the
 * reports and the ledger. Exit code 0 passes, 1 fails and 2 cannot judge.
 */
final readonly class VerdictCommand
{
    private const string NO_PLAN = 'There is no plan at %s. Run mutation-gate plan, and hand its plan to every job.';

    public static function command(Composition $composition): Command
    {
        return DeliverLater::option(new Command('verdict'))
            ->setDescription('Merge every shard\'s results, judge the floors, write reports and the ledger')
            ->addOption(FlowOptions::PLAN, mode: InputOption::VALUE_REQUIRED, description: 'The plan the shards ran')
            ->addOption(
                FlowOptions::RESULTS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'The directory the shards left their results in',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = DeliverLater::composed($composition->compose($input), $input, Stage::Verdict);

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $plan = self::planIn($composed, FlowOptions::path($input, FlowOptions::PLAN, Workspace::plan()));
                $results = FlowOptions::path($input, FlowOptions::RESULTS, Workspace::results());

                return $plan instanceof Plan
                    ? self::printed(
                        self::judgedOf($composed, $plan, $results),
                        $output,
                        Printing::console(),
                        $composed->adapters->project,
                    )
                    : Failed::because($output, $plan);
            });
    }

    /** The plan a file holds, or why it cannot be followed. */
    public static function planIn(Composed $composed, Path $file): Plan|CannotJudge
    {
        $plan = LastRun::planAt($composed->adapters->project, $file);

        return $plan instanceof Missing ? CannotJudge::because(sprintf(self::NO_PLAN, $file->value())) : $plan;
    }

    /**
     * A plan's results judged, held to the floors a command names or, where
     * it names none, to those its run decides; to the security sets alone,
     * where the plan was made with `--security`. With what was written, or
     * why they cannot be.
     */
    public static function judgedOf(
        Composed $composed,
        Plan $plan,
        Path $results,
        HeldTo|NotGiven $heldTo = new NotGiven(),
    ): Judged|Invalid|CannotJudge {
        $following = $composed->following($plan);
        $read = Results::read($plan, $results, $composed->adapters->project);

        return match (true) {
            ! $following instanceof Composed => $following,
            ! $read instanceof Results => $read,
            default => new Judging(
                $following->adapters,
                $following->settings,
                $following->setup,
                $following->reporting,
                $heldTo,
            )->verdict($plan, $read),
        };
    }

    /**
     * Say what was written and the verdict, printed as asked, and exit as the
     * verdict does; or say why there is none.
     */
    public static function printed(
        Judged|Invalid|CannotJudge $judged,
        OutputInterface $output,
        Printing $printing,
        Directory $project,
    ): int {
        if (! $judged instanceof Judged) {
            return Failed::because($output, $judged);
        }

        foreach ($judged->said as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $printing->verdict($judged->verdict, $output, $project);

        return $judged->exitCode()->value;
    }
}
