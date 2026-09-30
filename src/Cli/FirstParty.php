<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function class_exists;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\CodeQualityReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\KillMatrixFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
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
            ->withReporter(Name::of('github-summary'), StepSummary::fromOptions(...))
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
            ->withChangeSource(Name::of('git'), static fn(): ChangeSource => Git::at(self::HERE))
            ->withRepository(Name::of('git'), static fn(): Repository => Git::at(self::HERE))
            ->withChangeSource(Name::of('github'), static fn(): ChangeSource => self::github())
            ->withRepository(Name::of('github'), static fn(): Repository => self::github())
            ->withRunner(Name::of('infection'), Infection::fromOptions(...));
    }

    /** Git, with GitHub's word on what the default branch already proved. */
    private static function github(): ChangeSource&Repository
    {
        return PassedPullRequests::over(Git::at(self::HERE), HttpClient::create(), getenv());
    }
}
