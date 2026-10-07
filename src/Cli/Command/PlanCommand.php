<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\PublicationFile;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\LastRun;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate plan`: works out the reach, drops proved units, cuts the
 * shards, writes `.mutation-gate/plan.json`, hands the plan to the CI and
 * writes the pull request comment in its planned state (ADR-0009, decision 3).
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
        return DeliverLater::option(FlowOptions::planning(new Command('plan')))
            ->setDescription('Work out the reach, drop proved units, cut shards and print the plan for a CI')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = DeliverLater::composed(
                    FlowOptions::narrowed($composition->compose($input), $input),
                    $input,
                    Stage::Planned,
                );

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $made = self::planOf($composed, $input);

                return $made instanceof PlanMade
                    ? self::published($composed, $made, $output)
                    : Failed::because($output, $made);
            });
    }

    /** The plan the options ask for, with how its coverage map was measured; or why there is none. */
    public static function planOf(Composed $composed, InputInterface $input): PlanMade|CannotJudge
    {
        $mode = FlowOptions::mode($input, $composed->settings);
        $cut = FlowOptions::cut($input, $composed->settings);
        $matrix = FlowOptions::killMatrix($input);

        return match (true) {
            $mode instanceof CannotJudge => $mode,
            $cut instanceof CannotJudge => $cut,
            $matrix instanceof CannotJudge => $matrix,
            default => new Planning($composed->adapters, $composed->settings, $composed->setup)
                ->plan($mode, FlowOptions::coverage($input), $cut, $matrix),
        };
    }

    /** How the plan's coverage map was measured, where a line says so. */
    public static function saidHowMeasured(PlanMade $made, OutputInterface $aside): void
    {
        $said = $made->coverage();

        if (is_string($said)) {
            $aside->writeln($said, OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * Write the plan, hand it to the CI and write the pull request comment
     * in its planned state; or say why it cannot be.
     */
    private static function published(Composed $composed, PlanMade $made, OutputInterface $output): int
    {
        $plan = $made->plan();
        $written = LastRun::keep($composed->adapters->project, $plan);
        $publication = $written instanceof Written ? $composed->adapters->ci->publish($plan) : $written;
        $published = $publication instanceof Publication ? PublicationFile::written($publication) : $publication;
        $commented = $published instanceof Written
            ? $composed->reporting->planned($composed->settings, $plan)
            : $published;

        if (! is_array($commented)) {
            return Failed::because($output, $commented);
        }

        $aside = Aside::of($output);
        self::saidHowMeasured($made, $aside);
        $aside->writeln(sprintf('Wrote %s, with %d shards.', Workspace::plan()->value(), count($plan)));
        self::estimated($composed, $plan, $aside);
        self::saidIfUnpatched($composed, count($plan), $aside);

        foreach ($commented as $said) {
            $aside->writeln($said, OutputInterface::OUTPUT_RAW);
        }

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

        foreach ([...$estimates->lines(), ...$estimates->assumed($composed->adapters->processes())] as $line) {
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
