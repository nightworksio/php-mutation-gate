<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;

use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate plan`: works out the reach, drops proved units, cuts the
 * shards, writes `.mutation-gate/plan.json` and hands the plan to the CI.
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

        $output->writeln(sprintf('Wrote %s, with %d shards.', Workspace::plan()->value(), count($plan)));
        self::saidIfUnpatched($composed, count($plan), $output);

        return ExitCode::Passed->value;
    }

    /** One line where a sharded plan runs Pest without the patch, since each shard then pays a full opening run. */
    private static function saidIfUnpatched(Composed $composed, int $shards, OutputInterface $output): void
    {
        $identity = $composed->adapters->runner->identity($composed->adapters->withheld);
        $pest = $identity instanceof Identity && $identity->runner() === Pest::RUNNER;

        if ($pest && $shards > 1 && ! $composed->settings->pest()->patch()) {
            $output->writeln(sprintf(self::UNPATCHED, $shards));
        }
    }
}
