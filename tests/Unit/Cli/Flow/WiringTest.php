<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\LocalLedgers;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\MergedHeads;
use NightWorksIO\MutationGate\Adapter\GitHub\NoLedgers;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Pest\Pest as PestRunner;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpUnit;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Cores;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Mutators;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Pipeline;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Carry;
use NightWorksIO\MutationGate\Core\Verdict\Carrying;
use NightWorksIO\MutationGate\Core\Verdict\ChangesSince;
use NightWorksIO\MutationGate\Core\Verdict\Uncounted;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGateDefault\DefaultExtension;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Scratch::sweep();
});

/** This package's registry, with the fake runner beside it. */
function wiringRegistry(): Extensions
{
    return new ExtensionFake()->extend(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))));
}

/** The tokens of every CI the gate knows, past those every run withholds. */
function wiringEveryCi(): Withheld
{
    return Withheld::of(
        'CI_JOB_TOKEN',
        'CI_REPOSITORY_URL',
        'CI_JOB_JWT*',
        'CI_REGISTRY_PASSWORD',
        'CI_DEPLOY_PASSWORD',
        'CI_DEPENDENCY_PROXY_PASSWORD',
        'BUILDKITE_AGENT_ACCESS_TOKEN',
        'BUILDKITE_AGENT_TOKEN',
        'BUILDKITE_AGENT_JOB_API_TOKEN',
        'BUILDKITE_AGENT_JOB_API_SOCKET',
        'CIRCLE_OIDC_TOKEN*',
        'SYSTEM_ACCESSTOKEN',
        'AZURE_DEVOPS_EXT_PAT',
        'BITBUCKET_STEP_OIDC_TOKEN',
    );
}

/** What zero-config finds of the fixture project, which installs no static analyser. */
function wiringDetected(): Detected
{
    return new Detected(Directory::at(Flows::project()), Directory::at(sprintf('%s/vendor', Flows::project())));
}

/** The adapters these settings choose, started in these variables. */
function wiredOf(Settings $settings, Variables $environment): Adapters
{
    $adapters = new Wiring(wiringRegistry(), $environment, wiringDetected())
        ->adapters($settings, Directory::at(Flows::project()));

    return $adapters instanceof Adapters ? $adapters : throw new RuntimeException('The adapters could not be wired.');
}

it('wires the runner, the store, the learned cost model, the JSON plan and git where no CI runs', function (): void {
    $adapters = wiredOf(Flows::settings(), Variables::of([]));

    expect($adapters->runner)->toEqual(RunnerFake::ofTheFixture())
        ->and($adapters->ci)->toEqual(JsonPlan::fromOptions(Options::none()))
        ->and($adapters->costs)->toEqual(MeasuredCosts::fromOptions(Configs::options('{"secondsPerLine": {"": 0.2}}')))
        ->and($adapters->changes)->toEqual(Git::withholding('.', $adapters->withheld))
        ->and($adapters->repository)->toEqual(Git::withholding('.', $adapters->withheld))
        ->and($adapters->environment)->toEqual(Variables::of([]))
        ->and($adapters->withheld)->toEqual(Withheld::standard()->and(wiringEveryCi()))
        ->and($adapters->cores)->toEqual(Cores::counted())
        ->and($adapters->engine)->toEqual(NotGiven::value());
});

it('counts a plan\'s mutants with the default set, where the set is registered', function (): void {
    $registry = new DefaultExtension()->extend(wiringRegistry());
    $set = $registry->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());
    $adapters = new Wiring($registry, Variables::of([]), wiringDetected())->adapters(Flows::settings(), Directory::at(Flows::project()));

    expect($adapters instanceof Adapters ? $adapters->engine : $adapters)
        ->toEqual($set instanceof MutatorSet ? Enabled::of($set)->engine() : $set);
});

it('keeps a local run\'s proofs on the machine, and a CI run\'s in the store the config names', function (): void {
    $settings = Flows::settings(Proofs::s3('ledgers'));
    $keys = ['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret'];
    $local = Environment::during($keys, static fn(): Adapters => wiredOf($settings, Variables::of([])));
    $ci = Environment::during($keys, static fn(): Adapters => wiredOf($settings, Variables::of(['CI' => 'true'])));

    expect($local->proofs)->toEqual(LocalLedgers::over($ci->proofs))
        ->and($ci->proofs)->toBeInstanceOf(BucketLedger::class)
        ->and(wiredOf(Flows::settings(Proofs::directory('cache/ledger')), Variables::of([]))->proofs)
        ->toEqual(LedgerDirectory::at('cache/ledger'));
});

/**
 * What runs in a new git repository whose origin names this default branch, or none where it is empty.
 *
 * @template T
 *
 * @param  Closure(): T $run
 * @return T
 */
function wiringInRepository(string $originHead, Closure $run): mixed
{
    $here = getcwd();
    $repository = Scratch::directory();
    $git = static fn(string ...$arguments): int => new Process(['git', ...$arguments], $repository)->run();
    $git('init', '--quiet');

    if ($originHead !== '') {
        $git('symbolic-ref', 'refs/remotes/origin/HEAD', sprintf('refs/remotes/origin/%s', $originHead));
    }

    chdir($repository);

    try {
        return $run();
    } finally {
        chdir(is_string($here) ? $here : $repository);
    }
}

it('opens the store read-only for a CI job without its credentials, reading the default branch\'s scope alone', function (
    Settings $settings,
    string $gitLabDefault,
    string $originHead,
    string $branch,
): void {
    $unset = ['AWS_ACCESS_KEY_ID' => null, 'AWS_SECRET_ACCESS_KEY' => null];
    $environment = $gitLabDefault === ''
        ? []
        : ['GITLAB_CI' => 'true', 'CI_DEFAULT_BRANCH' => $gitLabDefault, 'CI_COMMIT_REF_NAME' => 'feature'];
    $ci = wiringInRepository($originHead, static fn(): Adapters => Environment::during(
        [...$unset, ...$environment],
        static fn(): Adapters => wiredOf($settings, Variables::of(['CI' => 'true', ...$environment])),
    ));

    expect($ci->proofs)->toEqual(
        PublicLedger::at(HttpClient::create(), 'https://ledgers.example.com', 'mutation-gate')
            ->onlyReading(Scope::branch($branch)),
    );
})->with([
    'the branch the config names' => [
        fn(): Settings => Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json(), Ci::defaultBranch('trunk')),
        '',
        'stable',
        'trunk',
    ],
    'where the config names none, the one the CI names' => [
        fn(): Settings => Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::gitlab()),
        'develop',
        'stable',
        'develop',
    ],
    'where neither names one, the one git names' => [
        fn(): Settings => Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json()),
        '',
        'stable',
        'stable',
    ],
    'where nothing names one, main' => [
        fn(): Settings => Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json()),
        '',
        '',
        'main',
    ],
]);

it('learns costs at the seconds a line the config sets, and at the standard ones otherwise', function (): void {
    $slow = wiredOf(Flows::settings(Shards::secondsPerLine('src/Slow', 0.5)), Variables::of([]))->costs;
    $unset = wiredOf(Flows::settings(), Variables::of([]))->costs;

    $slowly = Configs::options('{"secondsPerLine": {"": 0.2, "src/Slow": 0.5}}');

    expect($slow)->toEqual(MeasuredCosts::fromOptions($slowly))
        ->and($slow)->not->toEqual(MeasuredCosts::fromOptions(Configs::options('{"secondsPerLine": {"": 0.2}}')))
        ->and($unset)->toEqual(MeasuredCosts::fromOptions(Configs::options('{"secondsPerLine": {"": 0.2}}')));
});

it('plans for the CI its environment shows, the first it detects', function (
    Variables $environment,
    string $plan,
): void {
    expect(wiredOf(Flows::settings(), $environment)->ci::class)->toBe($plan);
})->with([
    'GitHub Actions' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true', 'GITLAB_CI' => 'true']), GitHubPlan::class],
    'GitLab' => [fn(): Variables => Variables::of(['GITLAB_CI' => 'true', 'BUILDKITE' => 'true']), GitLabPlan::class],
    'Buildkite' => [fn(): Variables => Variables::of(['BUILDKITE' => 'true', 'CIRCLECI' => 'true']), BuildkitePlan::class],
    'CircleCI' => [fn(): Variables => Variables::of(['CIRCLECI' => 'true']), CircleCiPlan::class],
    'a CI that is not set to true' => [
        fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'false', 'CIRCLECI' => 'yes']),
        JsonPlan::class,
    ],
]);

it('plans for the CI the config names, whatever the environment shows', function (): void {
    expect(wiredOf(Flows::settings(Ci::circleci()), Variables::of(['GITLAB_CI' => 'true']))->ci)
        ->toBeInstanceOf(CircleCiPlan::class);
});

it('hands GitLab\'s plan its template, and Buildkite\'s its step and its definition', function (): void {
    $settings = Flows::settings(
        Pipeline::gitlabTemplate('ci/gate.yml'),
        Pipeline::buildkiteStep(Option::nested('agents', Option::of('queue', 'gate'))),
        Pipeline::buildkiteDefinition('.buildkite/gate.yml'),
    );
    $gitlab = wiredOf($settings, Variables::of(['GITLAB_CI' => 'true']))->ci;
    $buildkite = wiredOf($settings, Variables::of(['BUILDKITE' => 'true']))->ci;

    expect($gitlab->definitions())->toEqual(Paths::of(Path::of('.gitlab-ci.yml'), Path::of('ci/gate.yml')))
        ->and($buildkite->definitions())->toEqual(Paths::of(Path::of('.buildkite/gate.yml')))
        ->and($buildkite)->toEqual(BuildkitePlan::fromOptions(Configs::options(
            '{"step": {"agents": {"queue": "gate"}}, "definition": ".buildkite/gate.yml"}',
        )));
});

it('withholds every run\'s credentials, the tokens of every CI, whichever runs it, and runner.withhold', function (
    string $variable,
): void {
    $settings = Flows::settings(Runner::uses('fake')->withholding(Withheld::of('DEPLOY_*')));

    expect(wiredOf($settings, Variables::of([$variable => 'true']))->withheld)
        ->toEqual(Withheld::standard()->and(wiringEveryCi())->and(Withheld::of('DEPLOY_*')));
})->with(['CircleCI' => ['CIRCLECI'], 'GitLab' => ['GITLAB_CI'], 'no CI' => ['HOME']]);

it('hands git what a run withholds, so no process git starts sees a CI token either', function (): void {
    $settings = Flows::settings(Runner::uses('fake')->withholding(Withheld::of('DEPLOY_*')));
    $variables = ['CI_JOB_TOKEN' => 'job', 'DEPLOY_KEY' => 'deploy'];
    $adapters = Environment::during($variables, static fn(): Adapters => wiredOf($settings, Variables::of([])));
    $plain = Environment::during($variables, static fn(): Git => Git::at('.'));
    $withheld = $adapters->withheld;
    $wired = Environment::during($variables, static fn(): Git => Git::withholding('.', $withheld));

    expect($adapters->changes)->toEqual($wired)
        ->and($adapters->repository)->toEqual($wired)
        ->and($adapters->changes)->not->toEqual($plain);
});

it('withholds the tokens of the CI the job runs on, whichever plan the config names', function (): void {
    $withheld = wiredOf(Flows::settings(Ci::json()), Variables::of(['GITLAB_CI' => 'true']))->withheld;

    expect(preg_match($withheld->pattern(), 'CI_JOB_TOKEN'))->toBe(1)
        ->and(preg_match($withheld->pattern(), 'BUILDKITE_AGENT_ACCESS_TOKEN'))->toBe(1)
        ->and(preg_match($withheld->pattern(), 'CI_PROJECT_NAME'))->toBe(0);
});

it('hands a plan the config names its template, its step and its definition', function (): void {
    $settings = static fn(Ci ...$named): Settings => Flows::settings(
        Pipeline::gitlabTemplate('ci/gate.yml'),
        Pipeline::buildkiteStep(Option::nested('agents', Option::of('queue', 'gate'))),
        Pipeline::buildkiteDefinition('.buildkite/gate.yml'),
        ...$named,
    );
    $gitlab = wiredOf($settings(Ci::gitlab()), Variables::of([]))->ci;
    $buildkite = wiredOf($settings(Ci::buildkite()), Variables::of([]))->ci;

    expect($gitlab->definitions())->toEqual(Paths::of(Path::of('.gitlab-ci.yml'), Path::of('ci/gate.yml')))
        ->and($buildkite)->toEqual(BuildkitePlan::fromOptions(Configs::options(
            '{"step": {"agents": {"queue": "gate"}}, "definition": ".buildkite/gate.yml"}',
        )));
});

it('reads changes through GitHub under GitHub Actions, by ci.check, trusting the store where ci.trustMergedPullRequests is true, withholding', function (): void {
    $variables = ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head', 'CI_JOB_TOKEN' => 'job'];
    $adapters = Environment::during($variables, static fn(): Adapters => wiredOf(
        Flows::settings(Ci::check('gate / verdict'), Ci::trustingMergedPullRequests()),
        Variables::of(['GITHUB_ACTIONS' => 'true']),
    ));
    $withheld = $adapters->withheld;
    $git = Environment::during($variables, static fn(): Git => Git::withholding('.', $withheld));
    $heads = new ReflectionProperty(PassedPullRequests::class, 'heads');
    $check = new ReflectionProperty(MergedHeads::class, 'check');
    $ledgers = new ReflectionProperty(MergedHeads::class, 'ledgers');
    $source = new ReflectionProperty(PassedPullRequests::class, 'source');
    $changed = $heads->getValue($adapters->changes);
    $standing = $heads->getValue($adapters->repository);

    expect($adapters->changes)->toBeInstanceOf(PassedPullRequests::class)
        ->and($adapters->repository)->toBeInstanceOf(PassedPullRequests::class)
        ->and($changed)->toBeInstanceOf(MergedHeads::class)
        ->and($changed instanceof MergedHeads ? $check->getValue($changed) : $changed)->toBe('gate / verdict')
        ->and($changed instanceof MergedHeads ? $ledgers->getValue($changed) : $changed)->toBe($adapters->proofs)
        ->and($standing instanceof MergedHeads ? $ledgers->getValue($standing) : $standing)->toBe($adapters->proofs)
        ->and($source->getValue($adapters->changes))->toEqual($git);
});

it('reads no pull request\'s ledger where ci.trustMergedPullRequests is false or not set', function (Settings $settings): void {
    $variables = ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head'];
    $adapters = Environment::during($variables, static fn(): Adapters => wiredOf($settings, Variables::of(['GITHUB_ACTIONS' => 'true'])));
    $heads = new ReflectionProperty(PassedPullRequests::class, 'heads');
    $ledgers = new ReflectionProperty(MergedHeads::class, 'ledgers');
    $changed = $heads->getValue($adapters->changes);
    $standing = $heads->getValue($adapters->repository);

    expect($changed instanceof MergedHeads ? $ledgers->getValue($changed) : $changed)->toEqual(NoLedgers::none())
        ->and($standing instanceof MergedHeads ? $ledgers->getValue($standing) : $standing)->toEqual(NoLedgers::none());
})->with([
    'false' => [fn(): Settings => Flows::settings(Ci::notTrustingMergedPullRequests())],
    'not set' => [fn(): Settings => Flows::settings()],
]);

it('cannot wire a runner the registry does not have, or one that refuses its options', function (): void {
    $picky = wiringRegistry()->withRunner(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('level', 'The level is a number.')),
    );
    $wired = static fn(Extensions $registry, string $runner): Adapters|Invalid|CannotJudge => new Wiring(
        $registry,
        Variables::of([]),
        wiringDetected(),
    )->adapters(Flows::settings(Runner::uses($runner)), Directory::at(Scratch::directory()));

    expect($wired(wiringRegistry(), 'nowhere'))->toEqual(CannotJudge::because('No runner is registered as "nowhere".'))
        ->and($wired($picky, 'picky'))->toEqual(
            Invalid::because(Problem::at('runner.with.level', 'The level is a number.')),
        );
});

it('hands the Pest runner pest.patch and its canary, so it says every key reads the canary', function (): void {
    $patched = wiredOf(Flows::settings(Runner::pest(), Pest::patched()), Variables::of([]))->runner->behaviour();
    $unpatched = wiredOf(Flows::settings(Runner::pest()), Variables::of([]))->runner->behaviour();

    expect($patched->readByEveryKey())->toEqual(Groups::of(Group::named('mutation-canary')))
        ->and($unpatched->readByEveryKey())->toEqual(Groups::none())
        ->and($unpatched->opensEachShard())->toBeTrue();
});

it('wires no static analyser where none is chosen, or where auto finds none installed', function (StaticCheck $static): void {
    expect(wiredOf(Flows::settings($static), Variables::of([]))->checker)->toEqual(NoAnalyser::configured())
        ->and(wiredOf(Flows::settings($static), Variables::of([]))->analyser)->toEqual(NoAnalyser::configured());
})->with([
    'none' => [fn(): StaticCheck => StaticCheck::none()],
    'auto, in a project that installs none' => [fn(): StaticCheck => StaticCheck::auto()],
]);

it('wires the analyser chosen, handing it staticCheck.config as its config', function (): void {
    $handed = [];
    $registry = wiringRegistry()->withStaticChecker(Name::of('fake'), static function (Options $options) use (&$handed): StaticCheckerFake {
        $handed[] = $options->path(Key::of('config'));

        return StaticCheckerFake::findingNothing();
    });
    $settings = Flows::settings(StaticCheck::uses('fake'), StaticCheck::config('config/analyser.neon'));
    $adapters = new Wiring($registry, Variables::of([]), wiringDetected())->adapters($settings, Directory::at(Flows::project()));

    expect($adapters instanceof Adapters ? $adapters->checker : $adapters)->toEqual(StaticCheckerFake::findingNothing())
        ->and($adapters instanceof Adapters ? $adapters->analyser : $adapters)
        ->toEqual(StaticCheckerFake::findingNothing()->identity(Withheld::standard()))
        ->and($handed)->toEqual([Path::of('config/analyser.neon')]);
});

/**
 * A project whose PHPStan, a stand-in, resolves parameters naming its own
 * root: its config, which includes another and a baseline, and a bootstrap
 * file, each with these contents.
 */
function wiringPhpStanProject(string $include = 'parameters: {}', string $baseline = 'parameters: {ignoreErrors: []}', string $bootstrap = '<?php'): string
{
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, 'phpstan-extra.neon', $include);
    Scratch::write($project, 'phpstan-baseline.neon', $baseline);
    Scratch::write($project, 'tests/bootstrap.php', $bootstrap);
    Scratch::write($project, 'vendor/bin/params.json', sprintf(
        '{"level": 9, "paths": ["%1$s/src"], "tmpDir": "%1$s/../tmp-%2$s",'
        . ' "allConfigFiles": ["%1$s/phpstan.neon", "%1$s/phpstan-extra.neon", "%1$s/phpstan-baseline.neon"],'
        . ' "bootstrapFiles": ["%1$s/tests/bootstrap.php"]}',
        $project,
        basename($project),
    ));

    return $project;
}

/** Who the analyser wired for a project is: PHPStan, read through its adapter, over the project's directory. */
function wiredAnalyserIn(string $project): AnalyserIdentity|NoAnalyser|CannotJudge
{
    $registry = wiringRegistry()->withStaticChecker(
        Name::of('stand-in'),
        static fn(Options $options): PhpStan|Invalid => PhpStan::fromOptions($options, $project, new LocalProcesses(new SystemClock())),
    );
    $adapters = new Wiring($registry, Variables::of([]), wiringDetected())
        ->adapters(Flows::settings(StaticCheck::uses('stand-in')), Directory::at($project));

    return $adapters instanceof Adapters ? $adapters->analyser : throw new RuntimeException('The adapters could not be wired.');
}

/** What decides src/Money.php's mutant set, and its source, in a run whose static analyser is this one. */
function wiringDigestsWith(AnalyserIdentity|NoAnalyser|CannotJudge $analyser): Digests
{
    return ContentKeys::of(
        Version::of('nightworksio/mutation-gate', '1.0.0', 'abc123'),
        '{}',
        Identity::of('pest', Versions::none(), Digest::of('platform')),
        $analyser instanceof AnalyserIdentity ? $analyser : NoAnalyser::configured(),
        Digest::of('installed'),
        Source::of(
            Fingerprints::of(Fingerprint::of(Path::of('src/Money.php'), Digest::of('money'))),
            CiDefinitions::none(),
            Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate.baseline.json'), Ignored::nothing(), Paths::none()),
        ),
        Tests::of(TestFiles::of(), Paths::none(), Paths::none()),
    )->digestsOf(Units::of(Unit::file(Path::of('src/Money.php'))));
}

/** Whether a kill by static analysis proved under one analyser carries into a run under another, at the same base. */
function wiringCarriesStaticKill(AnalyserIdentity|NoAnalyser|CannotJudge $then, AnalyserIdentity|NoAnalyser|CannotJudge $now): Carry|Uncounted
{
    $recorded = wiringDigestsWith($then);
    $source = $recorded->sourceOf(Path::of('src/Money.php'));
    $rejected = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '3', 0),
        '3',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        MutantStatus::Survived,
        Unmeasured::duration(),
    )->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'Method Money::add() should return int.')));
    $proof = Proof::held(
        Digest::sha256Of('old key'),
        Path::of('src/Money.php'),
        Mutants::of($rejected),
        ProvedKills::none(),
        Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    )->withInputs(Inputs::of($source instanceof Digest ? $source : Digest::of('none'), $recorded->mutation()));
    $carrying = Carrying::against(wiringDigestsWith($now), Digest::sha256Of('base'), TestNames::none(), CoverageMap::empty(), ChangesSince::none());
    $counted = $carrying->counted($proof);

    return $counted instanceof Proof ? $carrying->carry($counted, $rejected) : $counted;
}

it('keys the analyser by the configuration it resolves, so a static kill carries while nothing it read changed', function (): void {
    $project = wiringPhpStanProject();
    $analyser = wiredAnalyserIn($project);

    expect($analyser)->toBeInstanceOf(AnalyserIdentity::class)
        ->and($analyser instanceof AnalyserIdentity ? $analyser->config() : $analyser)
        ->not->toEqual(Digest::sha256Of("parameters:\n    level: 9\n"))
        ->and(wiringCarriesStaticKill($analyser, wiredAnalyserIn($project)))->toBe(Carry::Stands);
});

it('carries no static kill once the analyser\'s baseline, an included config or a bootstrap file reads otherwise', function (string $file, string $contents): void {
    $project = wiringPhpStanProject();
    $then = wiredAnalyserIn($project);
    Scratch::write($project, $file, $contents);

    expect(wiringCarriesStaticKill($then, wiredAnalyserIn($project)))->toBe(Uncounted::MutationChanged);
})->with([
    'the baseline' => ['phpstan-baseline.neon', "parameters:\n    ignoreErrors:\n        - '#return type#'\n"],
    'an included config' => ['phpstan-extra.neon', "services:\n    - Acme\\StrictRule\n"],
    'a bootstrap file' => ['tests/bootstrap.php', "<?php\ndefine('ACME_STRICT', true);\n"],
]);

it('keys the analyser alike for the same project at two roots', function (): void {
    expect(wiredAnalyserIn(wiringPhpStanProject()))->toEqual(wiredAnalyserIn(wiringPhpStanProject()));
});

it('keys the analyser by its config file where it cannot say the configuration it resolves', function (): void {
    $project = wiringPhpStanProject();
    Scratch::write($project, 'vendor/bin/params.exit', '1');

    expect(wiredAnalyserIn($project))->toEqual(AnalyserIdentity::of('phpstan', '2.2.16', Digest::sha256Of("parameters:\n    level: 9\n")));
});

it('tells Infection the bounds of each mutant\'s limit, and that the gate checks its survivors only where an analyser is wired', function (): void {
    $registry = wiringRegistry()->withStaticChecker(Name::of('fake'), static fn(): StaticCheckerFake => StaticCheckerFake::findingNothing());
    $wired = static fn(Runner $runner, StaticCheck $static): Adapters|Invalid|CannotJudge => new Wiring($registry, Variables::of([]), wiringDetected())
        ->adapters(Flows::settings($runner, $static, Timeouts::most(45)), Directory::at(Flows::project()));
    $checked = $wired(Runner::infection(), StaticCheck::uses('fake'));
    $unchecked = $wired(Runner::infection(), StaticCheck::none());
    $pest = $wired(Runner::pest(), StaticCheck::uses('fake'));

    expect($checked instanceof Adapters ? $checked->runner : $checked)
        ->toEqual(Infection::fromOptions(Configs::options('{"staticAnalysis": "gate", "timeout": 10.0, "most": 45.0}'), new CapDirectory(), new LocalProcesses(new SystemClock())))
        ->and($unchecked instanceof Adapters ? $unchecked->runner : $unchecked)
        ->toEqual(Infection::fromOptions(Configs::options('{"timeout": 10.0, "most": 45.0}'), new CapDirectory(), new LocalProcesses(new SystemClock())))
        ->and($pest instanceof Adapters ? $pest->runner : $pest)->not->toBeInstanceOf(Infection::class);
});

it('tells each built-in runner the mutators whose silence limit has a lower floor, as timeouts.tighter names them', function (Runner $runner, Closure $built): void {
    $adapters = new Wiring(wiringRegistry(), Variables::of([]), wiringDetected())
        ->adapters(Flows::settings($runner, StaticCheck::none(), Timeouts::tighter(5, 'Foreach_')), Directory::at(Flows::project()));
    $options = Configs::options('{"timeout": 10.0, "most": 300.0, "tighterFloor": 5.0, "tighterMutators": ["Foreach_"]}');

    expect($adapters instanceof Adapters ? $adapters->runner : $adapters)->toEqual($built($options));
})->with([
    'Infection' => [fn(): Runner => Runner::infection(), static fn(Options $options): object => Infection::fromOptions($options, new CapDirectory(), new LocalProcesses(new SystemClock()))],
]);

it('cannot wire an analyser the registry does not have', function (): void {
    $adapters = new Wiring(wiringRegistry(), Variables::of([]), wiringDetected())
        ->adapters(Flows::settings(StaticCheck::uses('nowhere')), Directory::at(Flows::project()));

    expect($adapters)->toEqual(CannotJudge::because('No static checker is registered as "nowhere".'));
});

it('tells an Azure store which scope is the default branch\'s, which its public container keeps', function (): void {
    $settings = Flows::settings(Proofs::azure('acme', 'ledgers', publicContainer: 'public'), Ci::json(), Ci::defaultBranch('trunk'));
    $token = ['MUTATION_GATE_AZURE_TOKEN' => 'eyJ'];
    $wired = Environment::during($token, static fn(): Adapters => wiredOf($settings, Variables::of(['CI' => 'true'])));
    $expected = Environment::during($token, static fn(): object => ContainerLedger::configured(
        Configs::options('{"account": "acme", "container": "ledgers", "prefix": "mutation-gate", "publicContainer": "public"}'),
        Variables::of(getenv()),
        HttpExchange::over(HttpClient::create()),
    ));

    expect($wired->proofs)->toEqual($expected instanceof ContainerLedger ? $expected->forDefaultBranch(Scope::branch('trunk')) : $expected);
});

it('tells the PHPUnit runner the bounds of each mutant\'s limit, timeouts.seconds and timeouts.most, and the mutators of the default set', function (): void {
    $registry = new DefaultExtension()->extend(wiringRegistry());
    $set = $registry->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());
    $mutators = $set instanceof MutatorSet ? [...$set] : [];
    $adapters = new Wiring($registry, Variables::of([]), wiringDetected())
        ->adapters(Flows::settings(Runner::phpunit(), Timeouts::seconds(45), Timeouts::most(90)), Directory::at(Flows::project()));

    expect($mutators)->not->toBe([])
        ->and($adapters instanceof Adapters ? $adapters->runner : $adapters)->toEqual(PhpUnit::fromOptions(
            Configs::options((string) json_encode(['timeout' => 45.0, 'most' => 90.0, 'mutators' => $mutators])),
            ComposerVendor::of('.'),
            new CapDirectory(),
            new LocalProcesses(new SystemClock()),
        ));
});

it('tells the PHPUnit runner no mutator where no extension registers the default set', function (): void {
    $runner = wiredOf(Flows::settings(Runner::phpunit()), Variables::of([]))->runner;

    expect($runner)->toBeInstanceOf(PhpUnit::class)
        ->and($runner->mutate(MutationRequest::of(Paths::none(), WholeSuite::tests())))
        ->toEqual(CannotJudge::because('The phpunit runner makes its mutants with the default mutator set, and no extension registers one.'));
});

it('tells each runner its timeouts and the classes of the registered mutators the config turns on: Pest and Infection beside their own, the PHPUnit runner beside the default set\'s', function (): void {
    $registry = new DefaultExtension()->extend(wiringRegistry())
        ->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class, RemoveEcho::class));
    $default = $registry->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());
    $wired = static fn(Runner $runner): Adapters|Invalid|CannotJudge => new Wiring($registry, Variables::of([]), wiringDetected())->adapters(
        Flows::settings($runner, Timeouts::seconds(45), Timeouts::most(90), Mutators::sets('acme'), Mutators::except('acme/RemoveEcho')),
        Directory::at(Flows::project()),
    );
    $pest = $wired(Runner::pest());
    $infection = $wired(Runner::infection());
    $phpunit = $wired(Runner::phpunit());
    $native = [...$default instanceof MutatorSet ? $default : [], PlusToMinus::class];

    expect($pest instanceof Adapters ? $pest->runner : $pest)->toEqual(PestRunner::fromOptions(
        Configs::options((string) json_encode(['timeout' => 45.0, 'most' => 90.0, 'mutators' => [PlusToMinus::class]])),
        ComposerVendor::of('.'),
        new CapDirectory(),
        new LocalProcesses(new SystemClock()),
    ))
        ->and($infection instanceof Adapters ? $infection->runner : $infection)->toEqual(Infection::fromOptions(
            Configs::options((string) json_encode(['timeout' => 45.0, 'most' => 90.0, 'mutators' => [PlusToMinus::class]])),
            new CapDirectory(),
            new LocalProcesses(new SystemClock()),
        ))
        ->and($phpunit instanceof Adapters ? $phpunit->runner : $phpunit)->toEqual(PhpUnit::fromOptions(
            Configs::options((string) json_encode(['timeout' => 45.0, 'most' => 90.0, 'mutators' => $native])),
            ComposerVendor::of('.'),
            new CapDirectory(),
            new LocalProcesses(new SystemClock()),
        ))
        ->and($pest instanceof Adapters ? array_map(static fn(object $mutator): string => $mutator::class, [...$pest->mutators]) : $pest)
        ->toBe([PlusToMinus::class])
        ->and($phpunit instanceof Adapters ? $phpunit->engine : $phpunit)
        ->toEqual(Enabled::of(MutatorSet::of(...$native))->engine());
});

it('tells the PHPUnit runner the default set\'s mutators less one mutators.except turns off, and refuses that under Pest', function (): void {
    $registry = new DefaultExtension()->extend(wiringRegistry());
    $default = $registry->registered(ExtensionPoint::MutatorSet, MutatorSet::defaultName());
    $mutators = [...Enabled::of($default instanceof MutatorSet ? $default : MutatorSet::of())];
    $off = $mutators[0]->name()->value();
    $wired = static fn(Runner $runner): Adapters|Invalid|CannotJudge => new Wiring($registry, Variables::of([]), wiringDetected())->adapters(
        Flows::settings($runner, Timeouts::seconds(45), Mutators::except($off)),
        Directory::at(Flows::project()),
    );
    $phpunit = $wired(Runner::phpunit());
    $pest = $wired(Runner::pest());

    expect($phpunit instanceof Adapters ? $phpunit->runner : $phpunit)->toEqual(PhpUnit::fromOptions(
        Configs::options((string) json_encode([
            'timeout' => 45.0,
            'mutators' => array_map(static fn(object $mutator): string => $mutator::class, array_slice($mutators, 1)),
        ])),
        ComposerVendor::of('.'),
        new CapDirectory(),
        new LocalProcesses(new SystemClock()),
    ))
        ->and($pest)->toEqual(Invalid::because(Problem::at(
            'mutators.except',
            sprintf("expected a mutator of a set in mutators.sets, got \"%s\": the pest runner runs its own mutators in place of the default set's", $off),
        )));
});

it('cannot wire a mutator set nobody registered, naming the one most likely meant', function (): void {
    $registry = wiringRegistry()->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class));

    expect(new Wiring($registry, Variables::of([]), wiringDetected())->adapters(
        Flows::settings(Runner::pest(), Mutators::sets('acmee')),
        Directory::at(Flows::project()),
    ))->toEqual(CannotJudge::because('No mutator set is registered as "acmee". Did you mean "acme"?'));
});

it('tells each runner the test directories the PHPUnit config declares, each glob expanded', function (): void {
    $project = Flows::project();
    Scratch::write($project, 'plugins/a/tests/ATest.php', '<?php');
    Scratch::write($project, 'plugins/b/tests/BTest.php', '<?php');
    Scratch::write($project, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests</directory></testsuite>
                <testsuite name="Plugins"><directory>plugins/*/tests</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    $wired = static fn(Runner $runner): Adapters|Invalid|CannotJudge => new Wiring(wiringRegistry(), Variables::of([]), wiringDetected())
        ->adapters(Flows::settings($runner, Timeouts::seconds(45)), Directory::at($project));
    $options = static fn(string ...$tests): string => (string) json_encode(['timeout' => 45.0, 'tests' => $tests]);
    $declared = ['tests', 'plugins/a/tests', 'plugins/b/tests'];
    $pest = $wired(Runner::pest());
    $phpunit = $wired(Runner::phpunit());
    $infection = $wired(Runner::infection());

    expect($pest instanceof Adapters ? $pest->runner : $pest)->toEqual(PestRunner::fromOptions(
        Configs::options($options(...$declared)),
        ComposerVendor::of('.'),
        new CapDirectory(),
        new LocalProcesses(new SystemClock()),
    ))
        ->and($phpunit instanceof Adapters ? $phpunit->runner : $phpunit)->toEqual(PhpUnit::fromOptions(
            Configs::options($options(...$declared)),
            ComposerVendor::of('.'),
            new CapDirectory(),
            new LocalProcesses(new SystemClock()),
        ))
        ->and($infection instanceof Adapters ? $infection->runner : $infection)->toEqual(Infection::fromOptions(
            Configs::options($options(...$declared)),
            new CapDirectory(),
            new LocalProcesses(new SystemClock()),
        ));
});

it('cannot wire a runner where the PHPUnit config it would read the test directories from is not XML', function (): void {
    $project = Flows::project();
    Scratch::write($project, 'phpunit.xml', '<phpunit><testsuites>');

    expect(new Wiring(wiringRegistry(), Variables::of([]), wiringDetected())->adapters(
        Flows::settings(Runner::pest()),
        Directory::at($project),
    ))->toEqual(CannotJudge::because('phpunit.xml is not XML, so the test suite it declares cannot be read.'));
});
