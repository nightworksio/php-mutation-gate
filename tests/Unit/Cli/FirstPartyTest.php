<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Project\AutoloadTrees;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitTrees;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Cli\Config\Presets;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;

$registry = static fn(): Extensions => new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)));

it('comes from this package', function (): void {
    expect(FirstParty::PACKAGE)->toBe('nightworksio/mutation-gate');
});

it('is named in this package\'s own composer.json', function (): void {
    $manifest = json_decode((string) file_get_contents(sprintf('%s/composer.json', dirname(__DIR__, 3))), associative: true);

    expect($manifest)->toMatchArray(['name' => FirstParty::PACKAGE])
        ->toHaveKey('extra.mutation-gate', ['extensions' => [FirstParty::class]]);
});

it('registers the directory and the bucket proof stores', function () use ($registry): void {
    expect(Lookup::in($registry())->proofStore(Name::of('directory'), Options::none()))
        ->toEqual(LedgerDirectory::at(LedgerDirectory::PATH))
        ->and(Lookup::in($registry())->proofStore(Name::of('s3'), Options::ofJson('{"bucket": "ledgers"}')))
        ->toBeInstanceOf(BucketLedger::class);
});

it('registers the cost model that learns from every shard', function () use ($registry): void {
    expect(Lookup::in($registry())->costModel(Name::of('learned'), Options::none()))->toBeInstanceOf(MeasuredCosts::class);
});

it('registers a CI plan for each CI it knows, and plain JSON', function () use ($registry): void {
    $plan = static fn(string $name): object => Lookup::in($registry())->ciPlan(Name::of($name), Options::none());

    expect($plan('github'))->toBeInstanceOf(GitHubPlan::class)
        ->and($plan('gitlab'))->toBeInstanceOf(GitLabPlan::class)
        ->and($plan('buildkite'))->toBeInstanceOf(BuildkitePlan::class)
        ->and($plan('circleci'))->toBeInstanceOf(CircleCiPlan::class)
        ->and($plan('json'))->toBeInstanceOf(JsonPlan::class);
});

it('registers git, and git with GitHub\'s word on its proofs, as change sources and repositories', function () use (
    $registry,
): void {
    $lookup = Lookup::in($registry());

    expect($lookup->changeSource(Name::of('git'), Options::none()))->toBeInstanceOf(Git::class)
        ->and($lookup->repository(Name::of('git'), Options::none()))->toBeInstanceOf(Git::class)
        ->and($lookup->changeSource(Name::of('github'), Options::none()))->toBeInstanceOf(ChangeSource::class)
        ->and($lookup->repository(Name::of('github'), Options::none()))->toBeInstanceOf(Repository::class);
});

it('registers a loader for every config format, the tree sources and the presets', function () use ($registry): void {
    $loader = static fn(string $name): object => Lookup::in($registry())->configLoader(Name::of($name), Options::none());

    expect($loader('php'))->toBeInstanceOf(PhpConfig::class)
        ->and($loader('json'))->toBeInstanceOf(JsonConfig::class)
        ->and($loader('yaml'))->toBeInstanceOf(YamlConfig::class)
        ->and($loader('neon'))->toBeInstanceOf(NeonConfig::class)
        ->and(Lookup::in($registry())->treeSource(Name::of('phpunit'), Options::none()))->toBeInstanceOf(PhpUnitTrees::class)
        ->and(Lookup::in($registry())->treeSource(Name::of('composer'), Options::none()))->toBeInstanceOf(AutoloadTrees::class)
        ->and(Lookup::in($registry())->preset(Name::of('laravel')))->toEqual(Presets::laravel());
});

it('registers Infection as a runner, built from the options the flows write', function () use ($registry): void {
    expect(Lookup::in($registry())->runner(Name::of('infection'), Options::none()))->toBeInstanceOf(Infection::class)
        ->and(Lookup::in($registry())->runner(Name::of('infection'), Options::ofJson('{"timeout": "ten"}')))->toBeInstanceOf(Invalid::class);
});

it('registers Pest as a runner, in the vendor directory Composer installed the project into', function () use (
    $registry,
): void {
    expect(Lookup::in($registry())->runner(Name::of('pest'), Options::none()))
        ->toEqual(Pest::fromOptions(Options::none(), ComposerVendor::of('.')));
});
