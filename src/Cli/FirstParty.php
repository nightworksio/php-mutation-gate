<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function class_exists;

use Closure;

use function getenv;

use NightWorksIO\MutationGate\Adapter\Alert\AlertReporter;
use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Adapter\Console\ProblemsReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\CodeQualityReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\KillMatrixFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\TestsReportFile;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Repository;
use Symfony\Component\HttpClient\HttpClient;

/**
 * This package's own adapters and presets, registered through the same
 * discovery as any other package's, and named in this package's
 * `composer.json`.
 */
final readonly class FirstParty implements Extension
{
    /** The Composer package this extension comes from. */
    public const string PACKAGE = 'nightworksio/mutation-gate';

    /** Where the gate runs: the project's root, which holds its repository. */
    private const string HERE = '.';

    public function extend(Extensions $extensions): Extensions
    {
        return Registered::config($extensions, class_exists(...))
            ->withProofStore(BuiltinStore::Directory->named(), LedgerDirectory::fromOptions(...))
            ->withProofStore(BuiltinStore::S3->named(), BucketLedger::fromOptions(...))
            ->withCostModel(Name::of('learned'), MeasuredCosts::fromOptions(...))
            ->withCiPlan(BuiltinCiPlan::GitHub->named(), GitHubPlan::fromOptions(...))
            ->withCiPlan(BuiltinCiPlan::GitLab->named(), GitLabPlan::fromOptions(...))
            ->withCiPlan(BuiltinCiPlan::Buildkite->named(), BuildkitePlan::fromOptions(...))
            ->withCiPlan(BuiltinCiPlan::CircleCi->named(), CircleCiPlan::fromOptions(...))
            ->withCiPlan(BuiltinCiPlan::Json->named(), JsonPlan::fromOptions(...))
            ->withRunner(
                BuiltinRunner::Pest->named(),
                static fn(Options $options): Pest|Invalid => Pest::fromOptions(
                    $options,
                    ComposerVendor::of(self::HERE),
                ),
            )
            ->withReporter(BuiltinReporter::Console->named(), ConsoleReport::fromOptions(...))
            ->withReporter(BuiltinReporter::Json->named(), JsonReportFile::fromOptions(...))
            ->withReporter(BuiltinReporter::JUnit->named(), JUnitReportFile::fromOptions(...))
            ->withReporter(BuiltinReporter::Sarif->named(), SarifReportFile::fromOptions(...))
            ->withReporter(BuiltinReporter::Html->named(), HtmlReportDirectory::fromOptions(...))
            ->withReporter(BuiltinReporter::GitHubAnnotations->named(), Annotations::fromOptions(...))
            ->withReporter(
                BuiltinReporter::GitHubSummary->named(),
                static fn(Options $options): Reporter => StepSummary::configured($options, new SystemClock()),
            )
            ->withReporter(BuiltinReporter::GitHubComment->named(), PullRequestComment::fromOptions(...))
            ->withReporter(
                BuiltinReporter::Badge->named(),
                static fn(Options $options): Reporter|Invalid => BadgeDirectory::configured(
                    $options,
                    new SystemClock(),
                ),
            )
            ->withReporter(BuiltinReporter::GitLab->named(), CodeQualityReportFile::fromOptions(...))
            ->withReporter(BuiltinReporter::KillMatrix->named(), KillMatrixFile::fromOptions(...))
            ->withReporter(BuiltinReporter::Tests->named(), TestsReportFile::fromOptions(...))
            ->withReporter(BuiltinReporter::Problems->named(), ProblemsReport::fromOptions(...))
            ->withReporter(BuiltinReporter::Slack->named(), $this->alerting(Channel::Slack))
            ->withReporter(BuiltinReporter::Discord->named(), $this->alerting(Channel::Discord))
            ->withReporter(BuiltinReporter::Webhook->named(), $this->alerting(Channel::Webhook))
            ->withReporter(
                BuiltinReporter::Otlp->named(),
                static fn(Options $options): Reporter|Invalid => OtlpReporter::configured($options, new SystemClock()),
            )
            ->withChangeSource(Name::of('git'), static fn(): ChangeSource => Git::at(self::HERE))
            ->withRepository(Name::of('git'), static fn(): Repository => Git::at(self::HERE))
            ->withChangeSource(Name::of('github'), static fn(): ChangeSource => self::github())
            ->withRepository(Name::of('github'), static fn(): Repository => self::github())
            ->withRunner(BuiltinRunner::Infection->named(), Infection::fromOptions(...));
    }

    /**
     * The reporter that sends alerts to this channel, reading the system's clock.
     *
     * @return Closure(Options): (Reporter|Invalid)
     */
    private function alerting(Channel $channel): Closure
    {
        return static fn(Options $options): Reporter|Invalid => AlertReporter::configured(
            $channel,
            $options,
            new SystemClock(),
        );
    }

    /** Git, with GitHub's word on what the default branch already proved. */
    private static function github(): ChangeSource&Repository
    {
        return PassedPullRequests::over(Git::at(self::HERE), HttpClient::create(), getenv());
    }
}
