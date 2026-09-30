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
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
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
            ->withProofStore(Name::of('directory'), LedgerDirectory::fromOptions(...))
            ->withProofStore(Name::of('s3'), BucketLedger::fromOptions(...))
            ->withCostModel(Name::of('learned'), MeasuredCosts::fromOptions(...))
            ->withCiPlan(Name::of('github'), GitHubPlan::fromOptions(...))
            ->withCiPlan(Name::of('gitlab'), GitLabPlan::fromOptions(...))
            ->withCiPlan(Name::of('buildkite'), BuildkitePlan::fromOptions(...))
            ->withCiPlan(Name::of('circleci'), CircleCiPlan::fromOptions(...))
            ->withCiPlan(Name::of('json'), JsonPlan::fromOptions(...))
            ->withRunner(
                Name::of('pest'),
                static fn(Options $options): Pest|Invalid => Pest::fromOptions(
                    $options,
                    ComposerVendor::of(self::HERE),
                ),
            )
            ->withReporter(Name::of('console'), ConsoleReport::fromOptions(...))
            ->withReporter(Name::of('json'), JsonReportFile::fromOptions(...))
            ->withReporter(Name::of('junit'), JUnitReportFile::fromOptions(...))
            ->withReporter(Name::of('sarif'), SarifReportFile::fromOptions(...))
            ->withReporter(Name::of('html'), HtmlReportDirectory::fromOptions(...))
            ->withReporter(Name::of('github-annotations'), Annotations::fromOptions(...))
            ->withReporter(
                Name::of('github-summary'),
                static fn(Options $options): Reporter => StepSummary::configured($options, new SystemClock()),
            )
            ->withReporter(Name::of('github-comment'), PullRequestComment::fromOptions(...))
            ->withReporter(
                Name::of('badge'),
                static fn(Options $options): Reporter|Invalid => BadgeDirectory::configured(
                    $options,
                    new SystemClock(),
                ),
            )
            ->withReporter(Name::of('gitlab'), CodeQualityReportFile::fromOptions(...))
            ->withReporter(Name::of('kill-matrix'), KillMatrixFile::fromOptions(...))
            ->withReporter(Name::of('tests'), TestsReportFile::fromOptions(...))
            ->withReporter(Name::of('problems'), ProblemsReport::fromOptions(...))
            ->withReporter(Name::of('slack'), $this->alerting(Channel::Slack))
            ->withReporter(Name::of('discord'), $this->alerting(Channel::Discord))
            ->withReporter(Name::of('webhook'), $this->alerting(Channel::Webhook))
            ->withReporter(
                Name::of('otlp'),
                static fn(Options $options): Reporter|Invalid => OtlpReporter::configured($options, new SystemClock()),
            )
            ->withChangeSource(Name::of('git'), static fn(): ChangeSource => Git::at(self::HERE))
            ->withRepository(Name::of('git'), static fn(): Repository => Git::at(self::HERE))
            ->withChangeSource(Name::of('github'), static fn(Options $options): ChangeSource => self::github($options))
            ->withRepository(Name::of('github'), static fn(Options $options): Repository => self::github($options))
            ->withRunner(Name::of('infection'), Infection::fromOptions(...));
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

    /**
     * Git, with GitHub's word on what the default branch already proved, by
     * the verdict's check-run its `check` option names; git's alone where it
     * names none.
     */
    private static function github(Options $options): ChangeSource&Repository
    {
        try {
            $check = Node::decode($options->json())->field('check')->text();
        } catch (NotInShape) {
            $check = '';
        }

        return PassedPullRequests::over(Git::at(self::HERE), HttpClient::create(), getenv(), $check);
    }
}
