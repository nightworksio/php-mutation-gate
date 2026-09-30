<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** This package's registry, with the fake runner beside it. */
function wiringRegistry(): Extensions
{
    return new ExtensionFake()->extend(new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE))));
}

/** The adapters these settings choose, started in these variables. */
function wiredOf(Settings $settings, Variables $environment): Adapters
{
    $adapters = new Wiring(wiringRegistry(), $environment)
        ->adapters($settings, Directory::at(Flows::project()));

    return $adapters instanceof Adapters ? $adapters : throw new RuntimeException('The adapters could not be wired.');
}

it('wires the runner, the store, the learned cost model, the JSON plan and git where no CI runs', function (): void {
    $adapters = wiredOf(Flows::settings(), Variables::of([]));

    expect($adapters->runner)->toEqual(RunnerFake::ofTheFixture())
        ->and($adapters->ci)->toEqual(JsonPlan::fromOptions(Options::none()))
        ->and($adapters->costs)->toEqual(MeasuredCosts::fromOptions(Options::none()))
        ->and($adapters->changes)->toEqual(Git::at('.'))
        ->and($adapters->repository)->toEqual(Git::at('.'))
        ->and($adapters->environment)->toEqual(Variables::of([]))
        ->and($adapters->withheld)->toEqual(Withheld::standard());
});

it('learns costs at the seconds a line the config sets, and at the standard ones otherwise', function (): void {
    $slow = wiredOf(Flows::settings(Shards::secondsPerLine('src/Slow', 0.5)), Variables::of([]))->costs;
    $unset = wiredOf(Flows::settings(), Variables::of([]))->costs;

    expect($slow)->toEqual(MeasuredCosts::fromOptions(Options::ofJson('{"secondsPerLine": {"src/Slow": 0.5}}')))
        ->and($slow)->not->toEqual(MeasuredCosts::fromOptions(Options::none()))
        ->and($unset)->toEqual(MeasuredCosts::fromOptions(Options::none()));
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
        ->and($buildkite)->toEqual(BuildkitePlan::fromOptions(Options::ofJson(
            '{"step": {"agents": {"queue": "gate"}}, "definition": ".buildkite/gate.yml"}',
        )));
});

it('withholds every run\'s credentials, the CI\'s tokens and runner.withhold', function (): void {
    $settings = Flows::settings(Runner::uses('fake')->withholding(Withheld::of('DEPLOY_*')));

    expect(wiredOf($settings, Variables::of(['CIRCLECI' => 'true']))->withheld)->toEqual(
        Withheld::standard()->and(Withheld::of('CIRCLE_OIDC_TOKEN*'))->and(Withheld::of('DEPLOY_*')),
    );
});

it('reads changes through GitHub under GitHub Actions, checked by ci.check and trusting the store', function (): void {
    $variables = ['GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_SHA' => 'head'];
    $adapters = Environment::during($variables, static fn(): Adapters => wiredOf(
        Flows::settings(Ci::check('gate / verdict')),
        Variables::of(['GITHUB_ACTIONS' => 'true']),
    ));
    $check = new ReflectionProperty(PassedPullRequests::class, 'check');
    $ledgers = new ReflectionProperty(PassedPullRequests::class, 'ledgers');

    expect($adapters->changes)->toBeInstanceOf(PassedPullRequests::class)
        ->and($adapters->repository)->toBeInstanceOf(PassedPullRequests::class)
        ->and($check->getValue($adapters->changes))->toBe('gate / verdict')
        ->and($ledgers->getValue($adapters->changes))->toBe($adapters->proofs)
        ->and($ledgers->getValue($adapters->repository))->toBe($adapters->proofs);
});

it('cannot wire a runner the registry does not have, or one that refuses its options', function (): void {
    $picky = wiringRegistry()->withRunner(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('level', 'The level is a number.')),
    );
    $wired = static fn(Extensions $registry, string $runner): Adapters|Invalid|CannotJudge => new Wiring(
        $registry,
        Variables::of([]),
    )->adapters(Flows::settings(Runner::uses($runner)), Directory::at(Scratch::directory()));

    expect($wired(wiringRegistry(), 'nowhere'))->toEqual(CannotJudge::because('No runner is registered as "nowhere".'))
        ->and($wired($picky, 'picky'))->toEqual(
            Invalid::because(Problem::at('runner.with.level', 'The level is a number.')),
        );
});
