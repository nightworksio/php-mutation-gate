<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_any;
use function is_array;

use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Rechecking;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Comment\State;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\RecheckedText;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate survivors [--plan=<file>]`: on a pull request, the last
 * run's survivors run again before the shards, for a signal within minutes
 * (ADR-0020, ADR-0106). It re-checks what the plan reaches, or,
 * without one, what a plan made as `plan` makes one would, within
 * `survivorsFirst.budget`; it writes the comment over its planned state and
 * the step summary, and says on standard output how many still survive, how
 * many it had no time for, and what became of each. Several jobs may share
 * it ({@see SharedRecheck}). It writes no ledger and judges nothing, so it
 * exits 0 whatever it found, and 2 where it cannot re-check.
 *
 * @internal
 */
final readonly class SurvivorsCommand
{
    public const string UNCHECKED = 'The last run\'s survivors could not be re-checked first: %s';

    public const string UNREAD
        = 'Nothing reads the re-checked state, as comment.states and outputs.automatic say, so none is re-checked.';

    private const string SECURITY_WITH_PLAN = <<<'SAID'
        survivors --plan re-checks what its plan was made for, so it takes no --security. Give it to plan.
        SAID;

    private const string SUITE_WITH_PLAN = <<<'SAID'
        survivors --plan re-checks what its plan was made for, so it takes no --suite. Give it to plan.
        SAID;

    public static function command(Composition $composition): Command
    {
        return SharedRecheck::options(DeliverLater::option(FlowOptions::planning(new Command('survivors'))))
            ->setDescription('Run the last run\'s survivors again first, on a pull request, before its shards')
            ->addOption(
                FlowOptions::PLAN,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Re-check what this plan reaches',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $planned = $input->getOption(FlowOptions::PLAN) !== null;
                $built = $composition->compose($input);
                $composed = DeliverLater::composed(
                    $planned ? $built : FlowOptions::narrowed($built, $input),
                    $input,
                    Stage::Survivors,
                );
                $lines = match (true) {
                    ! $composed instanceof Composed => $composed,
                    $planned && FlowOptions::isSecurityOnly($input) => CannotJudge::because(self::SECURITY_WITH_PLAN),
                    $planned && FlowOptions::isSuiteOnly($input) => CannotJudge::because(self::SUITE_WITH_PLAN),
                    default => self::said($composed, $input, $planned, Aside::of($output)),
                };

                if (! is_array($lines)) {
                    return Failed::because($output, $lines);
                }

                $output->writeln($lines, OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }

    /**
     * The survivors of the run's own scope re-checked before a plan's shards
     * run, the comment and the step summary written: what the run says of
     * it, a line each; or why it cannot re-check.
     *
     * @return list<string>|CannotJudge
     */
    public static function rechecked(Composed $composed, Plan $plan): array|CannotJudge
    {
        if (! self::isRead($composed, $plan)) {
            return [self::UNREAD];
        }

        $rechecked = new Rechecking($composed->adapters, $composed->settings, $composed->setup)->recheck($plan);

        return $rechecked instanceof CannotJudge ? $rechecked : self::reported($composed, $plan, $rechecked);
    }

    /**
     * The same, as a run that goes on whatever it found says it: a re-check
     * that could not run is one line, never the run's end, and one a pull
     * request's run would not expect says nothing.
     *
     * @return list<string>
     */
    public static function noted(Composed $composed, Plan $plan): array
    {
        if (! self::isRead($composed, $plan)) {
            return [];
        }

        $rechecked = new Rechecking($composed->adapters, $composed->settings, $composed->setup)->recheck($plan);

        return match (true) {
            $rechecked instanceof CannotJudge => [sprintf(self::UNCHECKED, $rechecked->why())],
            $rechecked instanceof NoRecheck && ! $rechecked->wasExpected() => [],
            default => self::reported($composed, $plan, $rechecked),
        };
    }

    /**
     * What the run says of the survivors it re-checked, the comment and the step summary written, or of why it
     * re-checked none.
     *
     * @return list<string>
     */
    public static function reported(Composed $composed, Plan $plan, Rechecked|NoRecheck $rechecked): array
    {
        return $rechecked instanceof NoRecheck ? [$rechecked->why()] : [
            ...RecheckedText::lines($rechecked),
            ...$composed->reporting->rechecked($composed->settings, $plan, $rechecked),
        ];
    }

    /**
     * Whether anything reads the re-checked state: outside CI whoever ran this, and in CI a comment or a summary, as
     * `comment.states` lets it.
     */
    private static function isRead(Composed $composed, Plan $plan): bool
    {
        $reporters = $composed->reporting->chosenReporters($composed->settings, $plan->runOn());

        return ! $composed->adapters->environment->inCi()
            || $composed->settings->effective()->output()->reports()->comment()->writes()->writes(State::Rechecked)
            && is_array($reporters)
            && array_any(
                $reporters,
                static fn(object $reporter): bool => $reporter instanceof StepSummary
                    || $reporter instanceof Commenting,
            );
    }

    /** @return list<string>|CannotJudge */
    private static function said(
        Composed $composed,
        InputInterface $input,
        bool $planned,
        OutputInterface $aside,
    ): array|CannotJudge {
        $made = $planned
            ? VerdictCommand::planIn($composed, FlowOptions::path($input, FlowOptions::PLAN, Workspace::plan()))
            : PlanCommand::planOf($composed, $input);
        $plan = $made instanceof PlanMade ? $made->plan() : $made;
        $following = $plan instanceof Plan && $planned ? $composed->following($plan) : $composed;

        return match (true) {
            ! $plan instanceof Plan => $plan,
            ! $following instanceof Composed => $following,
            SharedRecheck::isAsked($input) => SharedRecheck::said(
                $following,
                $plan,
                $input,
                $aside,
                self::isRead($following, $plan),
            ),
            default => self::rechecked($following, $plan),
        };
    }
}
