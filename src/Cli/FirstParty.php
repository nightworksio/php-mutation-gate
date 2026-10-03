<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function class_exists;

use Closure;

use function getcwd;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Alert\AlertReporter;
use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Bitbucket\BitbucketPlan;
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
use NightWorksIO\MutationGate\Adapter\Filesystem\SonarReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\TestsReportFile;
use NightWorksIO\MutationGate\Adapter\Gcs\BucketLedger as GcsBucket;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\Config\KeyedStore;
use NightWorksIO\MutationGate\Cli\Config\PublicBucket;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinCostModel;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\ProofStore;
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
    /** Where the gate runs: the project's root, which holds its repository. */
    private const string HERE = '.';

    public function extend(Extensions $extensions): Extensions
    {
        $environment = Variables::of(getenv());
        $public = new PublicBucket(HttpClient::create());
        $s3 = $this->keyed(BuiltinStore::S3, BucketLedger::fromOptions(...), $public->s3(...), $environment);
        $gcs = $this->keyed(
            BuiltinStore::Gcs,
            static fn(Options $options): ProofStore|Invalid => GcsBucket::configured(
                $options,
                $environment,
                HttpExchange::over(HttpClient::create()),
            ),
            $public->gcs(...),
            $environment,
        );
        $azure = $this->keyed(
            BuiltinStore::Azure,
            static fn(Options $options): ProofStore|Invalid => ContainerLedger::configured(
                $options,
                $environment,
                HttpExchange::over(HttpClient::create()),
            ),
            $public->azure(...),
            $environment,
        );

        return Registered::config($extensions, class_exists(...))
            ->withProofStore(BuiltinStore::Directory->named(), LedgerDirectory::fromOptions(...))
            ->withProofStore(BuiltinStore::S3->named(), $s3)
            ->withProofStore(BuiltinStore::Gcs->named(), $gcs)
            ->withProofStore(BuiltinStore::Azure->named(), $azure)
            ->withCostModel(BuiltinCostModel::Learned->named(), MeasuredCosts::fromOptions(...))
            ->withCiPlan(
                BuiltinCiPlan::GitHub->named(),
                GitHubPlan::fromOptions(...),
                GitHubPlan::withheld(),
                GitHubPlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::GitLab->named(),
                GitLabPlan::fromOptions(...),
                GitLabPlan::withheld(),
                GitLabPlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::Buildkite->named(),
                BuildkitePlan::fromOptions(...),
                BuildkitePlan::withheld(),
                BuildkitePlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::CircleCi->named(),
                CircleCiPlan::fromOptions(...),
                CircleCiPlan::withheld(),
                CircleCiPlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::Azure->named(),
                AzurePlan::fromOptions(...),
                AzurePlan::withheld(),
                AzurePlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::Bitbucket->named(),
                BitbucketPlan::fromOptions(...),
                BitbucketPlan::withheld(),
                BitbucketPlan::marker(),
            )
            ->withCiPlan(
                BuiltinCiPlan::Json->named(),
                JsonPlan::fromOptions(...),
                JsonPlan::withheld(),
                JsonPlan::marker(),
            )
            ->withRunner(
                BuiltinRunner::Pest->named(),
                static fn(Options $options): Pest|Invalid => Pest::fromOptions(
                    $options,
                    ComposerVendor::of(self::HERE),
                    new CapDirectory(),
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
            ->withReporter(
                BuiltinReporter::Sonar->named(),
                static fn(Options $options): Reporter|Invalid => SonarReportFile::configured(
                    $options,
                    Guide::ofInstalled(InstalledGate::version()->version()),
                ),
            )
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
            ->withChangeSource(
                BuiltinVersionControl::Git->named(),
                static fn(Options $options): ChangeSource => self::git($options),
            )
            ->withRepository(
                BuiltinVersionControl::Git->named(),
                static fn(Options $options): Repository => self::git($options),
            )
            ->withChangeSource(
                BuiltinVersionControl::GitHub->named(),
                static fn(Options $options): ChangeSource => self::github($options),
            )
            ->withRepository(
                BuiltinVersionControl::GitHub->named(),
                static fn(Options $options): Repository => self::github($options),
            )
            ->withRunner(
                BuiltinRunner::Infection->named(),
                static fn(Options $options): Infection|Invalid => Infection::fromOptions($options, new CapDirectory()),
            )
            ->withStaticChecker(
                BuiltinAnalyser::Mago->named(),
                static fn(Options $options): Mago|Invalid => Mago::fromOptions(
                    $options,
                    self::root(),
                    ComposerVendor::on(self::root()),
                ),
            )
            ->withStaticChecker(
                BuiltinAnalyser::PhpStan->named(),
                static fn(Options $options): PhpStan|Invalid => PhpStan::fromOptions($options, self::root()),
            );
    }

    /**
     * An object store, read-only through its public URL in a job without the credentials this environment
     * would hold.
     *
     * @param  Closure(Options): (ProofStore|Invalid) $keyed   the store, built from its options
     * @param  Closure(Options): (ProofStore|Invalid) $keyless the store read-only, built from the same options
     * @return Closure(Options): (ProofStore|Invalid)
     */
    private function keyed(BuiltinStore $store, Closure $keyed, Closure $keyless, Variables $environment): Closure
    {
        return KeyedStore::of($store->credentials(), $keyed, $keyless, $environment)->build(...);
    }

    /** The project's root, where the gate runs, as an absolute path, which the analysers name files by. */
    private static function root(): string
    {
        $here = getcwd();

        return is_string($here) ? $here : self::HERE;
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
        $check = $options->text(Key::of('check'));

        return PassedPullRequests::over(
            self::git($options),
            HttpClient::create(),
            getenv(),
            is_string($check) ? $check : '',
        );
    }

    /**
     * Git in the project, withholding what every run withholds and the names
     * and globs its `withhold` option lists, so git's own children never see
     * a credential the project's code may not.
     */
    private static function git(Options $options): Git
    {
        $withhold = $options->texts(Key::of('withhold'));

        return Git::withholding(
            self::HERE,
            $withhold instanceof Listed ? Withheld::standard()->and(Withheld::of(...$withhold)) : Withheld::standard(),
        );
    }
}
