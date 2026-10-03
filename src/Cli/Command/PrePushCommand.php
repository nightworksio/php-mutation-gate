<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\ScoreChanging;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Push\Pushes;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;

use function sprintf;
use function stream_get_contents;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate pre-push`, which the pre-push hook calls with git's
 * arguments and the refs git is about to push on standard input. For each
 * base a pushed ref is read since, the commit the remote holds or, for a new
 * ref, the merge base with the default branch, it runs change-scoped under
 * `local.prePushBudget`, prints each reached tree's score change, then the
 * verdict, and exits as the worst verdict does: anything but 0 blocks the
 * push, an unjudged mutant included (ADR-0010, decision 2).
 */
final readonly class PrePushCommand
{
    private const string REMOTE = 'remote';

    private const string URL = 'url';

    private const string NOTHING = 'Nothing is pushed, so there is nothing to judge.';

    private const string MORE_TIME = <<<'SAID'
        More time judges what the budget left: vendor/bin/mutation-gate run --changed-since=%s
        SAID;

    private function __construct(
        private Composed $composed,
        private InputInterface $input,
        private Printing $printing,
        private OutputInterface $output,
    ) {
    }

    public static function command(Composition $composition): Command
    {
        return FlowOptions::editing(new Command(Hook::PrePush->value))
            ->setDescription(
                'Judge the commits being pushed, as CI will, after printing each reached tree\'s score change',
            )
            ->addArgument(self::REMOTE, InputArgument::OPTIONAL, 'The remote pushed to, as git hands it to the hook')
            ->addArgument(self::URL, InputArgument::OPTIONAL, 'The remote\'s URL, as git hands it to the hook')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $pushes = Pushes::read(self::handed($input));
                $printing = FlowOptions::printing($input);
                $composed = $composition->compose($input);
                $composed = $composed instanceof Composed ? self::budgeted($composed, $input) : $composed;

                return match (true) {
                    $pushes instanceof CannotJudge => Failed::because($output, $pushes),
                    $printing instanceof CannotJudge => Failed::because($output, $printing),
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    default => new self($composed, $input, $printing, $output)->judged($pushes),
                };
            });
    }

    /** What git hands the hook on standard input. */
    private static function handed(InputInterface $input): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $handed = stream_get_contents($stream ?? STDIN);

        return is_string($handed) ? $handed : '';
    }

    /** The composition with `local.prePushBudget` as the run's budget, unless `--budget` sets one for this run. */
    private static function budgeted(Composed $composed, InputInterface $input): Composed|Invalid
    {
        return CommandLine::from($input)->budget instanceof NotGiven
            ? $composed->budgeted($composed->settings->local()->prePushBudget())
            : $composed;
    }

    private function judged(Pushes $pushes): int
    {
        $adapters = $this->composed->adapters;
        $defaultBranch = $this->composed->settings->ci()->defaultBranch();
        $standing = Standing::of($adapters->ci, $adapters->repository, $defaultBranch);

        return $standing instanceof Standing
            ? $this->judgedAt($standing, $pushes)
            : Failed::because($this->output, $standing);
    }

    private function judgedAt(Standing $standing, Pushes $pushes): int
    {
        $defaultBranch = $standing->fetchedDefaultBranch();
        $judged = $pushes->judged($standing->head(), $defaultBranch);

        if ($judged instanceof CannotJudge) {
            return Failed::because($this->output, $judged);
        }

        if (count($judged) === 0) {
            $this->printing->note(self::NOTHING, $this->output);

            return ExitCode::Passed->value;
        }

        $this->printing->begin($this->output, $this->composed->adapters->project);
        $code = ExitCode::Passed->value;
        $deadline = $this->running()->deadline();

        foreach ($judged as $ref) {
            $code = max($code, $this->since($ref->base($defaultBranch), $deadline));
        }

        return $code;
    }

    /**
     * One change-scoped run since a base, by the deadline every run of the
     * push shares: the score change, the verdict, and what judges what it left.
     */
    private function since(Revision $base, Deadline|Unlimited $deadline): int
    {
        $judged = $this->run($base, $deadline);

        if ($judged instanceof Judged) {
            $this->scoreChange();
        }

        $code = VerdictCommand::printed($judged, $this->output, $this->printing, $this->composed->adapters->project);

        if ($judged instanceof Judged && $judged->verdict->wasCutShort()) {
            $this->printing->note(sprintf(self::MORE_TIME, $base->name()), $this->output);
        }

        return $code;
    }

    private function run(Revision $base, Deadline|Unlimited $deadline): Judged|Invalid|CannotJudge
    {
        $composed = $this->composed;
        $plan = new Planning($composed->adapters, $composed->settings, $composed->setup)->plan(
            Mode::since($base->name()),
            FlowOptions::coverage($this->input),
            FlowOptions::configuredCut($composed->settings),
        );
        $results = Workspace::results();
        $ran = $plan instanceof Plan ? $this->running()->runAllBy($plan, $results, $deadline) : $plan;

        return match (true) {
            $ran instanceof CannotJudge => $ran,
            $plan instanceof Plan => VerdictCommand::judgedOf($composed, $plan, $results, HeldTo::TreesAndNewCode),
        };
    }

    private function running(): Running
    {
        return new Running($this->composed->adapters, $this->composed->settings, $this->composed->setup);
    }

    /** Each reached tree's score change, as `pre-commit` prints it (ADR-0015, decision 10). */
    private function scoreChange(): void
    {
        $composed = $this->composed;
        $text = new ScoreChanging($composed->adapters, $composed->settings, $composed->setup)->text();

        if (is_string($text)) {
            $this->printing->note($text, $this->output);
        }
    }
}
