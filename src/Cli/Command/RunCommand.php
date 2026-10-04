<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate run --plan=<file>` mutates one shard of a plan and leaves
 * its result; it exits 0 once the result is written, whatever its mutants
 * did. Without `--plan` it plans, runs every shard and judges them in one
 * process, as `mutation-gate` with no command does, leaving its plan and
 * results in the workspace for `explain`; run in full outside CI,
 * it then writes every floor it raised, and every missing one, into the
 * baseline, and says which lines to commit. With `--output=problems` it
 * prints the verdict as one line per result, for an editor.
 */
final readonly class RunCommand
{
    private const string SECURITY_WITH_PLAN
        = 'A shard makes the mutants its plan was made for, so run --plan takes no --security. Give it to plan.';

    public static function command(Composition $composition): Command
    {
        return FlowOptions::editing(FlowOptions::planning(new Command('run')))
            ->setDescription('Plan, run and judge in one process; or, with --plan, run one shard of a plan')
            ->addOption(FlowOptions::PLAN, mode: InputOption::VALUE_REQUIRED, description: 'Run one shard of this plan')
            ->addOption(FlowOptions::SHARD, mode: InputOption::VALUE_REQUIRED, description: 'The shard to run')
            ->addOption(
                FlowOptions::RESULTS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Where the shards leave their results',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $planned = $input->getOption(FlowOptions::PLAN) !== null;
                $composed = $planned
                    ? $composition->compose($input)
                    : FlowOptions::narrowed($composition->compose($input), $input);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $planned && FlowOptions::isSecurityOnly($input)
                        => Failed::because($output, CannotJudge::because(self::SECURITY_WITH_PLAN)),
                    $planned => self::oneShard($composed, $input, $output),
                    default => self::allInOne($composed, $input, $output),
                };
            });
    }

    private static function oneShard(Composed $composed, InputInterface $input, OutputInterface $output): int
    {
        $plan = VerdictCommand::planIn($composed, FlowOptions::path($input, FlowOptions::PLAN, Workspace::plan()));
        $shard = FlowOptions::shard($input);
        $results = FlowOptions::path($input, FlowOptions::RESULTS, Workspace::results());
        $written = match (true) {
            $plan instanceof CannotJudge => $plan,
            $shard instanceof CannotJudge => $shard,
            default => self::ranShard($composed->following($plan), $plan, $shard, $results),
        };

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    /** One shard of a plan run as the plan was made, narrowed where it was made with `--security`. */
    private static function ranShard(
        Composed|CannotJudge $following,
        Plan $plan,
        ShardId|Absent $shard,
        Path $results,
    ): Written|CannotJudge {
        return $following instanceof Composed
            ? new Running($following->adapters, $following->settings, $following->setup)->run($plan, $shard, $results)
            : $following;
    }

    private static function allInOne(Composed $composed, InputInterface $input, OutputInterface $output): int
    {
        $printing = FlowOptions::printing($input);

        if ($printing instanceof CannotJudge) {
            return Failed::because($output, $printing);
        }

        $printing->begin($output, $composed->adapters->project);
        $plan = PlanCommand::planOf($composed, $input);
        $results = FlowOptions::path($input, FlowOptions::RESULTS, Workspace::results());
        $judged = $plan instanceof Plan ? self::judgedAll($composed, $plan, $results) : $plan;
        $local = FlowOptions::isFull($input) && ! $composed->adapters->environment->inCi();

        return VerdictCommand::printed(
            $judged instanceof Judged && $local ? self::raised($composed, $judged) : $judged,
            $output,
            $printing,
            $composed->adapters->project,
        );
    }

    /** A plan left in the workspace for `explain`, every shard of it run, and their results judged. */
    private static function judgedAll(Composed $composed, Plan $plan, Path $results): Judged|Invalid|CannotJudge
    {
        $kept = LastRun::keep($composed->adapters->project, $plan);
        $ran = $kept instanceof Written
            ? new Running($composed->adapters, $composed->settings, $composed->setup)->runAll($plan, $results)
            : $kept;

        return $ran instanceof CannotJudge ? $ran : VerdictCommand::judgedOf($composed, $plan, $results);
    }

    /**
     * A local full run's verdict, having written every floor it raised and
     * every missing one into the baseline, with the lines to commit.
     */
    private static function raised(Composed $composed, Judged $judged): Judged|CannotJudge
    {
        $baselines = new Baselines($composed->adapters, $composed->settings->floors()->baseline());
        $raised = new Raising($baselines)->raise(
            $judged->baseline,
            $judged->verdict->trees(),
            $judged->verdict->sets()->security(),
        );

        return $raised instanceof CannotJudge ? $raised : $judged->saying(...$raised);
    }
}
