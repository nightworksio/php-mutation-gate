<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;
use function str_contains;

/**
 * The reporters a verdict runs besides the console, which runs always: each
 * entry of `reports`; GitHub's annotations and step summary under GitHub
 * Actions; the sticky comment on a pull request's run with a token to write
 * it; and the badge and trend in CI on the default branch (ADR-0009).
 */
final readonly class Reporting
{
    /** What chose the reporters the run chooses on GitHub. */
    public function __construct(
        private Chosen $chosen,
        private Variables $environment,
        private DeliveryDirectory|NotGiven $later = new NotGiven(),
    ) {
    }

    /**
     * The same, leaving what each reporter whose sending needs a credential would send in this delivery, for
     * `deliver` to send, as `--deliver-later` asks (ADR-0007 decision 5): the comment is chosen on a pull request
     * whether or not this run holds a token.
     */
    public function deliveringLater(DeliveryDirectory $delivery): self
    {
        return new self($this->chosen, $this->environment, $delivery);
    }

    /** @return list<Reporter>|Invalid|CannotJudge */
    public function reporters(Settings $settings, RunOn $runOn): array|Invalid|CannotJudge
    {
        $chosen = $this->chosenReporters($settings, $runOn);
        $later = $this->later;

        return is_array($chosen) && $later instanceof DeliveryDirectory
            ? array_map(
                static fn(Reporter $reporter): Reporter => $reporter instanceof Deferring
                    ? DeliveredReport::of($reporter, $later)
                    : $reporter,
                $chosen,
            )
            : $chosen;
    }

    /**
     * The sticky comment in its planned state, written before any shard runs
     * (ADR-0009, decision 3): what each comment the run chooses said of it,
     * written or not; nothing where the run chooses no comment.
     *
     * @return list<string>|Invalid|CannotJudge
     */
    public function planned(Settings $settings, Plan $plan): array|Invalid|CannotJudge
    {
        $reporters = $this->chosenReporters($settings, $plan->runOn());

        if (! is_array($reporters)) {
            return $reporters;
        }

        $work = PlanEstimates::of($plan, $settings->shards()->setup())->work();
        $said = [];

        foreach ($reporters as $reporter) {
            if ($reporter instanceof PullRequestComment) {
                $written = $this->plannedBy($reporter, $work);
                $said[] = $written instanceof Written ? $written->said() : $written->why();
            }
        }

        return $said;
    }

    /** The comment in its planned state, written, or left in the delivery under `--deliver-later`. */
    private function plannedBy(PullRequestComment $comment, PlannedWork $work): Written|NotWritten|CannotJudge
    {
        return $this->later instanceof DeliveryDirectory
            ? $this->later->adding(static fn(Delivery $delivery): Delivery => $comment->plannedLater($work, $delivery))
            : $comment->planned($work);
    }

    /** @return list<Reporter>|Invalid|CannotJudge */
    private function chosenReporters(Settings $settings, RunOn $runOn): array|Invalid|CannotJudge
    {
        $reporters = [];

        foreach ([...$this->listed($settings), ...$this->byTheRun($settings, $runOn)] as $reporter) {
            if (! $reporter instanceof Reporter) {
                return $reporter;
            }

            $reporters[] = $reporter;
        }

        return $reporters;
    }

    /**
     * The last run's survivors re-checked (ADR-0020, decision 21): what the
     * sticky comment, over its planned state alone, and the step summary
     * said of them, written or not; nothing where the run chooses neither;
     * and where the reporters cannot be built, why, since the re-check is a
     * signal that ends no run.
     *
     * @return list<string>
     */
    public function rechecked(Settings $settings, Plan $plan, Rechecked $rechecked): array
    {
        $reporters = $this->chosenReporters($settings, $plan->runOn());

        if (! is_array($reporters)) {
            return $this->why($reporters);
        }

        $said = [];

        foreach ($reporters as $reporter) {
            $written = match (true) {
                $reporter instanceof PullRequestComment, $reporter instanceof StepSummary
                    => $reporter->rechecked($rechecked),
                default => NotGiven::value(),
            };
            $said = match (true) {
                $written instanceof Written => [...$said, $written->said()],
                $written instanceof NotWritten => [...$said, $written->why()],
                default => $said,
            };
        }

        return $said;
    }

    /** @return list<string> why the reporters cannot be built: each problem at its path, or the one reason */
    private function why(Invalid|CannotJudge $unbuilt): array
    {
        if ($unbuilt instanceof CannotJudge) {
            return [$unbuilt->why()];
        }

        $why = [];

        foreach ($unbuilt as $problem) {
            $why[] = sprintf('%s: %s', $problem->path(), $problem->message());
        }

        return $why;
    }

    /** @return list<Reporter|Invalid|CannotJudge> one for each entry of `reports`, with its path in its options */
    private function listed(Settings $settings): array
    {
        $reporters = [];

        foreach ([...$settings->reports()] as $index => $report) {
            $path = $report->path();
            $choice = $report->reporter();
            $options = $path instanceof Path
                ? $choice->options()->overPath(Key::of('path'), $path)
                : $choice->options();
            $reporters[] = $this->chosen->reporter(Choice::of($choice->use()->value(), $options), $index);
        }

        return $reporters;
    }

    /** @return list<Reporter|Invalid|CannotJudge> the reporters where the run is and what it runs on choose */
    private function byTheRun(Settings $settings, RunOn $runOn): array
    {
        $none = Options::none();
        $badge = Choice::of(
            BuiltinReporter::Badge->value,
            Options::of(Json::object(Member::of('colors', $settings->badge()->written()))),
        );

        return [
            ...$this->onGitHub() ? [
                $this->chosen->reporterChosenBy(
                    Variables::GITHUB_ACTIONS,
                    Choice::of(BuiltinReporter::GitHubAnnotations->value, $none),
                ),
                $this->chosen->reporterChosenBy(
                    Variables::GITHUB_ACTIONS,
                    Choice::of(BuiltinReporter::GitHubSummary->value, $none),
                ),
            ] : [],
            ...$this->onAPullRequestThatCanBeCommentedOn()
                ? [$this->chosen->reporterChosenBy(
                    Variables::GITHUB_ACTIONS,
                    Choice::of(BuiltinReporter::GitHubComment->value, $none),
                )]
                : [],
            ...$this->environment->inCi() && $this->onDefaultBranch($runOn)
                ? [$this->chosen->reporterChosenBy(BuiltinReporter::Badge->value, $badge)]
                : [],
        ];
    }

    private function onGitHub(): bool
    {
        return $this->environment->onGitHubActions();
    }

    /** On GitHub, on a pull request, with the token that comments, or with the comment left for `deliver`. */
    private function onAPullRequestThatCanBeCommentedOn(): bool
    {
        return $this->onGitHub()
            && str_contains($this->environment->valueOf('GITHUB_EVENT_NAME'), 'pull_request')
            && ($this->later instanceof DeliveryDirectory || $this->environment->valueOf('GITHUB_TOKEN') !== '');
    }

    private function onDefaultBranch(RunOn $runOn): bool
    {
        $scope = $runOn->scope();
        $default = $runOn->defaultBranch();

        return $scope instanceof Scope && $default instanceof Scope && $scope->equals($default);
    }
}
