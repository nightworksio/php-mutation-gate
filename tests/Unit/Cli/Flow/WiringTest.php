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
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Cores;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\SetEngine;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Flows;
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
        'CI_JOB_JWT*',
        'CI_REGISTRY_PASSWORD',
        'CI_DEPLOY_PASSWORD',
        'CI_DEPENDENCY_PROXY_PASSWORD',
        'BUILDKITE_AGENT_ACCESS_TOKEN',
        'BUILDKITE_AGENT_TOKEN',
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
        ->toEqual($set instanceof MutatorSet ? SetEngine::of($set) : $set);
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
        Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json(), Ci::defaultBranch('trunk')),
        '',
        'stable',
        'trunk',
    ],
    'where the config names none, the one the CI names' => [
        Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::gitlab()),
        'develop',
        'stable',
        'develop',
    ],
    'where neither names one, the one git names' => [
        Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json()),
        '',
        'stable',
        'stable',
    ],
    'where nothing names one, main' => [
        Flows::settings(Proofs::s3('ledgers', publicUrl: 'https://ledgers.example.com'), Ci::json()),
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
    'GitHub Actions' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITLAB_CI' => 'true']), GitHubPlan::class],
    'GitLab' => [Variables::of(['GITLAB_CI' => 'true', 'BUILDKITE' => 'true']), GitLabPlan::class],
    'Buildkite' => [Variables::of(['BUILDKITE' => 'true', 'CIRCLECI' => 'true']), BuildkitePlan::class],
    'CircleCI' => [Variables::of(['CIRCLECI' => 'true']), CircleCiPlan::class],
    'a CI that is not set to true' => [
        Variables::of(['GITHUB_ACTIONS' => 'false', 'CIRCLECI' => 'yes']),
        JsonPlan::class,
    ],
]);

it('plans for the CI the config names, whatever the environment shows', function (): void {
    expect(wiredOf(Flows::settings(Ci::circleci()), Variables::of(['GITLAB_CI' => 'true']))->ci)
        ->toBeInstanceOf(CircleCiPlan::class);
});

it('hands GitLab\'s plan its template, and Buildkite\'s its step and its definition', function (): void {
    $settings = Flows::settings(
        Ci::gitlabTemplate('ci/gate.yml'),
        Ci::buildkiteStep(Option::nested('agents', Option::of('queue', 'gate'))),
        Ci::buildkiteDefinition('.buildkite/gate.yml'),
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
        Ci::gitlabTemplate('ci/gate.yml'),
        Ci::buildkiteStep(Option::nested('agents', Option::of('queue', 'gate'))),
        Ci::buildkiteDefinition('.buildkite/gate.yml'),
        ...$named,
    );
    $gitlab = wiredOf($settings(Ci::gitlab()), Variables::of([]))->ci;
    $buildkite = wiredOf($settings(Ci::buildkite()), Variables::of([]))->ci;

    expect($gitlab->definitions())->toEqual(Paths::of(Path::of('.gitlab-ci.yml'), Path::of('ci/gate.yml')))
        ->and($buildkite)->toEqual(BuildkitePlan::fromOptions(Configs::options(
            '{"step": {"agents": {"queue": "gate"}}, "definition": ".buildkite/gate.yml"}',
        )));
});

it('reads changes through GitHub under GitHub Actions, by ci.check, trusting the store, withholding', function (): void {
    $variables = ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head', 'CI_JOB_TOKEN' => 'job'];
    $adapters = Environment::during($variables, static fn(): Adapters => wiredOf(
        Flows::settings(Ci::check('gate / verdict')),
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
    'none' => [StaticCheck::none()],
    'auto, in a project that installs none' => [StaticCheck::auto()],
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

it('tells Infection the gate checks its survivors where an analyser is wired, and leaves it alone otherwise', function (): void {
    $registry = wiringRegistry()->withStaticChecker(Name::of('fake'), static fn(): StaticCheckerFake => StaticCheckerFake::findingNothing());
    $wired = static fn(Runner $runner, StaticCheck $static): Adapters|Invalid|CannotJudge => new Wiring($registry, Variables::of([]), wiringDetected())
        ->adapters(Flows::settings($runner, $static), Directory::at(Flows::project()));
    $checked = $wired(Runner::infection(), StaticCheck::uses('fake'));
    $unchecked = $wired(Runner::infection(), StaticCheck::none());
    $pest = $wired(Runner::pest(), StaticCheck::uses('fake'));

    expect($checked instanceof Adapters ? $checked->runner : $checked)
        ->toEqual(Infection::fromOptions(Configs::options('{"staticAnalysis": "gate"}'), new CapDirectory()))
        ->and($unchecked instanceof Adapters ? $unchecked->runner : $unchecked)
        ->toEqual(Infection::fromOptions(Configs::options('{}'), new CapDirectory()))
        ->and($pest instanceof Adapters ? $pest->runner : $pest)->not->toBeInstanceOf(Infection::class);
});

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
