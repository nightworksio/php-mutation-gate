<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

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
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\Reporter;

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
    public function __construct(private Chosen $chosen, private Variables $environment)
    {
    }

    /** @return list<Reporter>|Invalid|CannotJudge */
    public function reporters(Settings $settings, RunOn $runOn): array|Invalid|CannotJudge
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

    private function onAPullRequestThatCanBeCommentedOn(): bool
    {
        return $this->onGitHub()
            && str_contains($this->environment->valueOf('GITHUB_EVENT_NAME'), 'pull_request')
            && $this->environment->valueOf('GITHUB_TOKEN') !== '';
    }

    private function onDefaultBranch(RunOn $runOn): bool
    {
        $scope = $runOn->scope();
        $default = $runOn->defaultBranch();

        return $scope instanceof Scope && $default instanceof Scope && $scope->equals($default);
    }
}
