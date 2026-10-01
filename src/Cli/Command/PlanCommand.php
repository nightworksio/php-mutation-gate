<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate plan`: works out the reach, drops proved units, cuts the
 * shards, writes `.mutation-gate/plan.json` and hands the plan to the CI.
 * What it wrote it says on standard error, since a CI may read the plan's
 * own document from standard output.
 */
final readonly class PlanCommand
{
    private const string UNPATCHED = <<<'SAID'
        Pest runs without the gate's patch, so each of the %d shards pays a full opening run under coverage.
        Set pest.patch to true to have every shard reuse the map the plan kept.
        SAID;

    public static function command(Composition $composition): Command
    {
        return FlowOptions::planning(new Command('plan'))
            ->setDescription('Work out the reach, drop proved units, cut shards and print the plan for a CI')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $plan = self::planOf($composed, $input);

                return $plan instanceof Plan
                    ? self::published($composed, $plan, $output)
                    : Failed::because($output, $plan);
            });
    }

    /** The plan the options ask for, or why there is none. */
    public static function planOf(Composed $composed, InputInterface $input): Plan|CannotJudge
    {
        $mode = FlowOptions::mode($input);
        $cut = FlowOptions::cut($input, $composed->settings);

        return match (true) {
            $mode instanceof CannotJudge => $mode,
            $cut instanceof CannotJudge => $cut,
            default => new Planning($composed->adapters, $composed->settings, $composed->setup)
                ->plan($mode, FlowOptions::coverage($input), $cut),
        };
    }

    /** Write the plan and hand it to the CI; or say why it cannot be. */
    private static function published(Composed $composed, Plan $plan, OutputInterface $output): int
    {
        $written = $composed->adapters->project->write(Workspace::plan(), Contents::of(PlanFile::encode($plan)));
        $published = $written instanceof Written ? $composed->adapters->ci->publish($plan) : $written;

        if ($published instanceof CannotJudge) {
            return Failed::because($output, $published);
        }

        $aside = Aside::of($output);
        $aside->writeln(sprintf('Wrote %s, with %d shards.', Workspace::plan()->value(), count($plan)));
        self::estimated($composed, $plan, $aside);
        self::saidIfUnpatched($composed, count($plan), $aside);

        return ExitCode::Passed->value;
    }

    /**
     * What each shard and the run are expected to take, what that rests on,
     * and, where it rests on a measured first run, how many mutants each
     * shard's runner is taken to run at once; and where `shards.target`
     * cannot be met under `shards.max`, a line that says so.
     */
    private static function estimated(Composed $composed, Plan $plan, OutputInterface $aside): void
    {
        $shards = $composed->settings->shards();
        $estimates = PlanEstimates::of($plan, $shards->setup());

        $adapters = $composed->adapters;
        $processes = $adapters->runner->behaviour()->parallelism()->processes($adapters->cores);

        foreach ([...$estimates->lines(), ...$estimates->assumed($processes)] as $line) {
            $aside->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($estimates->unmet($shards->target(), $shards->max()) as $warning) {
            $aside->writeln($warning->text(), OutputInterface::OUTPUT_RAW);
        }
    }

    /** One line where a sharded plan's runner has each shard pay a full opening run, as Pest without the patch does. */
    private static function saidIfUnpatched(Composed $composed, int $shards, OutputInterface $output): void
    {
        if ($shards > 1 && $composed->adapters->runner->behaviour()->opensEachShard()) {
            $output->writeln(sprintf(self::UNPATCHED, $shards));
        }
    }
}
