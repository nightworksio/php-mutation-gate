<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate run --plan=<file>` mutates one shard of a plan and leaves
 * its result; it exits 0 once the result is written, whatever its mutants
 * did. Without `--plan` it plans, runs every shard and judges them in one
 * process, as `mutation-gate` with no command does; run in full outside CI,
 * it then writes every floor it raised, and every missing one, into the
 * baseline, and says which lines to commit.
 */
final readonly class RunCommand
{
    public static function command(Composition $composition): Command
    {
        return FlowOptions::planning(new Command('run'))
            ->setDescription('Plan, run and judge in one process; or, with --plan, run one shard of a plan')
            ->addOption(FlowOptions::PLAN, mode: InputOption::VALUE_REQUIRED, description: 'Run one shard of this plan')
            ->addOption(FlowOptions::SHARD, mode: InputOption::VALUE_REQUIRED, description: 'The shard to run')
            ->addOption(
                FlowOptions::RESULTS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Where the shards leave their results',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $input->getOption(FlowOptions::PLAN) === null => self::allInOne($composed, $input, $output),
                    default => self::oneShard($composed, $input, $output),
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
            default => new Running($composed->adapters, $composed->settings, $composed->setup)
                ->run($plan, $shard, $results),
        };

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    private static function allInOne(Composed $composed, InputInterface $input, OutputInterface $output): int
    {
        $plan = PlanCommand::planOf($composed, $input);
        $results = FlowOptions::path($input, FlowOptions::RESULTS, Workspace::results());
        $running = new Running($composed->adapters, $composed->settings, $composed->setup);
        $ran = $plan instanceof Plan ? $running->runAll($plan, $results) : $plan;
        $judged = match (true) {
            $ran instanceof CannotJudge => $ran,
            $plan instanceof Plan => VerdictCommand::judgedOf($composed, $plan, $results),
        };
        $local = FlowOptions::isFull($input) && ! $composed->adapters->environment->inCi();

        return VerdictCommand::printed(
            $judged instanceof Judged && $local ? self::raised($composed, $judged) : $judged,
            $output,
        );
    }

    /**
     * A local full run's verdict, having written every floor it raised and
     * every missing one into the baseline, with the lines to commit.
     */
    private static function raised(Composed $composed, Judged $judged): Judged|CannotJudge
    {
        $baselines = new Baselines($composed->adapters, $composed->settings->floors()->baseline());
        $raised = new Raising($baselines)->raise($judged->baseline, $judged->verdict->trees());

        return $raised instanceof CannotJudge ? $raised : $judged->saying(...$raised);
    }
}
