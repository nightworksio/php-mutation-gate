<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Console\Indented;
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
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Sample\SampleText;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate plan`: works out the reach, drops proved units, cuts the
 * shards, writes `.mutation-gate/plan.json`, hands the plan to the CI and
 * writes the pull request comment in its planned state (ADR-0009).
 * What it wrote it says on standard error, since a CI may read the plan's
 * own document from standard output.
 *
 * @internal
 */
final readonly class PlanCommand
{
    /** The option that has a plan plan nothing where the run cannot start without a fresh map (ADR-0135). */
    public const string EARLY_ONLY = 'early-only';

    /** The option that has a plan stop at its selection, which no shard runs (ADR-0290). */
    public const string SELECTION_ONLY = 'selection-only';

    private const string SELECTED = 'Wrote %s, the selection alone, with %d units to run: no shard runs it.';

    private const string UNPATCHED = <<<'SAID'
        Pest runs without the gate's patch, so each of the %d shards pays a full opening run under coverage.
        Set pest.patch to true to have every shard reuse the map the plan kept.
        SAID;

    public static function command(Composition $composition): Command
    {
        return DeliverLater::option(FlowOptions::planning(new Command(Verb::Plan->value)))
            ->setDescription('Work out the reach, drop proved units, cut shards and print the plan for a CI')
            ->addOption(
                self::EARLY_ONLY,
                mode: InputOption::VALUE_NONE,
                description: 'Plan nothing where the run cannot start without a fresh coverage map',
            )
            ->addOption(
                self::SELECTION_ONLY,
                mode: InputOption::VALUE_NONE,
                description: 'Stop at the selection: the units to run and why, uncut and untimed, which no shard runs',
            )
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

                return match (true) {
                    ! $made instanceof PlanMade => Failed::because($output, $made),
                    self::isOn($input, self::SELECTION_ONLY) => self::selected($composed, $made, $output),
                    default => self::published($composed, $made, $output),
                };
            });
    }

    /** The plan the options ask for, with how its coverage map was measured; or why there is none. */
    public static function planOf(Composed $composed, InputInterface $input): PlanMade|CannotJudge
    {
        $mode = FlowOptions::mode($input, $composed->settings);
        $cut = FlowOptions::pulled(FlowOptions::cut($input, $composed->settings), $composed);
        $wall = StreamWall::of($composed->adapters->processes()->count());
        $cut = $cut instanceof Cut ? $cut->streamedOver($wall) : $cut;
        $matrix = FlowOptions::killMatrix($input);

        return match (true) {
            $mode instanceof CannotJudge => $mode,
            $cut instanceof CannotJudge => $cut,
            $matrix instanceof CannotJudge => $matrix,
            default => new Planning(
                $composed->adapters,
                $composed->settings,
                $composed->setup,
                selectionOnly: self::isOn($input, self::SELECTION_ONLY),
            )->plan(
                $mode,
                FlowOptions::coverage($input),
                $cut,
                $matrix,
                earlyOnly: self::isOn($input, self::EARLY_ONLY),
            ),
        };
    }

    /** How the plan's coverage map was measured, where a line says so, and each limit it learned. */
    public static function saidHowMeasured(PlanMade $made, OutputInterface $aside): void
    {
        $said = $made->coverage();

        if (is_string($said)) {
            $aside->writeln($said, OutputInterface::OUTPUT_RAW);
        }

        foreach ($made->learned() as $learned) {
            $aside->writeln($learned, OutputInterface::OUTPUT_RAW);
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
        $commands = $composed->setup->command->jobs();
        $jobs = $composed->reporting->deliversLater() ? $commands->deliveringLater() : $commands;
        $publication = $written instanceof Written ? $composed->adapters->ci->publish($plan, $jobs) : $written;
        $published = $publication instanceof Publication ? PublicationFile::written($publication) : $publication;
        $commented = $published instanceof Written
            ? $composed->reporting->planned($composed->settings, $plan)
            : $published;

        if (! is_array($commented)) {
            return Failed::because($output, $commented);
        }

        $aside = Aside::of($output);

        foreach ($made->unread() as $warning) {
            $aside->writeln($warning->text(), OutputInterface::OUTPUT_RAW);
        }

        self::saidHowMeasured($made, $aside);
        self::saidReach($plan, $aside);
        self::saidWritten($plan, $aside);
        self::estimated($composed, $plan, $aside);
        self::saidIfUnpatched($composed, count($plan), $aside);

        foreach (SampleText::planned($plan->considered()->sample()) as $said) {
            $aside->writeln($said, OutputInterface::OUTPUT_RAW);
        }

        foreach ($commented as $said) {
            $aside->writeln($said, OutputInterface::OUTPUT_RAW);
        }

        return ExitCode::Passed->value;
    }

    /**
     * Write a plan that stops at its selection, marked so that no shard runs
     * it, and say why it reaches what it does and how many units it selected;
     * it is handed to no CI and comments nowhere (ADR-0290).
     */
    private static function selected(Composed $composed, PlanMade $made, OutputInterface $output): int
    {
        $plan = $made->plan();
        $written = LastRun::keepSelection($composed->adapters->project, $plan);

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $aside = Aside::of($output);

        foreach ($made->unread() as $warning) {
            $aside->writeln($warning->text(), OutputInterface::OUTPUT_RAW);
        }

        self::saidHowMeasured($made, $aside);
        self::saidReach($plan, $aside);
        $units = 0;

        foreach ($plan as $shard) {
            $units += count($shard->units());
        }

        $aside->writeln(sprintf(self::SELECTED, Workspace::plan()->value(), $units));

        return ExitCode::Passed->value;
    }

    /** Whether the command takes this option and it was given. */
    private static function isOn(InputInterface $input, string $option): bool
    {
        return $input->hasOption($option) && $input->getOption($option) === true;
    }

    /** Why the plan reaches what it does, what reaches every unit of a package first, before its shards. */
    private static function saidWritten(Plan $plan, OutputInterface $aside): void
    {
        $shards = count($plan);
        $noun = $shards === 1 ? 'shard' : 'shards';
        $aside->writeln(sprintf('Wrote %s, with %d %s.', Workspace::plan()->value(), $shards, $noun));
    }

    private static function saidReach(Plan $plan, OutputInterface $aside): void
    {
        $said = [];

        foreach ($plan->considered()->reach()->wideningFirst() as $reason) {
            $said[] = $reason->text();
        }

        if ($said === []) {
            return;
        }

        $aside->writeln('Reach');

        foreach (Indented::lines($said) as $line) {
            $aside->writeln($line, OutputInterface::OUTPUT_RAW);
        }
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
        $wall = StreamWall::of($composed->adapters->processes()->count());
        $estimates = PlanEstimates::of($plan, $shards->setup(), $wall);

        $summary = $estimates->summary(
            $composed->adapters->processes(),
            $shards->target(),
            $shards->max(),
            $shards->stop()->after(
                $plan->briefing()->learned()->jobs()->setupOr($shards->setup()),
                $plan->briefing()->learned()->jobs()->windDownOr(Seconds::of(JobStop::WIND_DOWN_SECONDS)),
            ),
        );

        foreach ([...$estimates->lines(), ...$summary] as $line) {
            $aside->writeln($line, OutputInterface::OUTPUT_RAW);
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
