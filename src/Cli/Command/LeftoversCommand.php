<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;
use function is_float;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverCutting;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate leftovers --plan=<file> --results=<dir> [--coverage=<dir>]`:
 * after the shards, cuts the mutants each one left at its deadline into the
 * shares a second wave of jobs judges (ADR-0190), writes them beside the
 * results, and prints on standard output the matrix of those jobs, a JSON
 * list of each share's shard and number, `[]` where no shard left any.
 *
 * @internal
 */
final readonly class LeftoversCommand
{
    public const string NAME = 'leftovers';

    private const string UNREAD
        = 'Shards %s left no result, so no share holds their mutants and the verdict cannot judge them.';

    public static function command(Composition $composition): Command
    {
        return new Command(self::NAME)
            ->setDescription('Cut the mutants the shards left at their deadline into shares for a second wave of jobs')
            ->addOption(FlowOptions::PLAN, mode: InputOption::VALUE_REQUIRED, description: 'The plan the shards ran')
            ->addOption(
                FlowOptions::RESULTS,
                mode: InputOption::VALUE_REQUIRED,
                description: 'The directory the shards left their results in',
            )
            ->addOption(
                FlowOptions::COVERAGE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'The directory of the plan\'s coverage map, whose times predict each mutant\'s',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $cut = $composed instanceof Composed ? self::cut($composed, $input) : $composed;

                if (! $cut instanceof LeftoverShares) {
                    return Failed::because($output, $cut);
                }

                $output->writeln(JsonText::compact($cut->matrix()), OutputInterface::OUTPUT_RAW);
                self::saidUnread($cut, $output);

                return ExitCode::Passed->value;
            });
    }

    /** A line on the error output naming the shards that left no result, whose mutants no share lists. */
    private static function saidUnread(LeftoverShares $cut, OutputInterface $output): void
    {
        $unread = $cut->unread();
        if ($unread !== []) {
            Aside::of($output)->writeln(sprintf(self::UNREAD, implode(', ', $unread)), OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * The shares cut from the results the options name, for jobs that stop
     * where the plan's learned jobs leave them, priced at the second pass's
     * factor the plan learned; or why none could be cut.
     */
    private static function cut(Composed $composed, InputInterface $input): LeftoverShares|CannotJudge
    {
        $plan = VerdictCommand::planIn($composed, FlowOptions::path($input, FlowOptions::PLAN, Workspace::plan()));

        if (! $plan instanceof Plan) {
            return $plan;
        }

        $shards = $composed->settings->shards();
        $jobs = $plan->briefing()->learned()->jobs();
        $passTwo = $jobs->passTwo();
        $processes = $composed->adapters->processes()->count();

        return new LeftoverCutting($composed->adapters)->cut(
            $plan,
            FlowOptions::path($input, FlowOptions::RESULTS, Workspace::results()),
            FlowOptions::path($input, FlowOptions::COVERAGE, Workspace::coverage()),
            $shards->stop()->after(
                $jobs->setupOr($shards->setup()),
                $jobs->windDownOr(Seconds::of(JobStop::WIND_DOWN_SECONDS)),
            ),
            is_float($passTwo) ? StreamWall::of($processes, $passTwo) : StreamWall::of($processes),
        );
    }
}
