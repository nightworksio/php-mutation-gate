<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\CircleCi\CircleCiPlan;
use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\GitHub\GitHubPlan;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Adapter\Json\JsonPlan;
use NightWorksIO\MutationGate\Adapter\S3\BucketLedger;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;

$registry = static fn(): Extensions => new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)));

it('comes from this package', function (): void {
    expect(FirstParty::PACKAGE)->toBe('nightworksio/mutation-gate');
});

it('is named in this package\'s own composer.json', function (): void {
    $manifest = json_decode((string) file_get_contents(sprintf('%s/composer.json', dirname(__DIR__, 3))), associative: true);

    expect($manifest)->toMatchArray(['name' => FirstParty::PACKAGE, 'extra' => ['mutation-gate' => ['extensions' => [FirstParty::class]]]]);
});

it('registers the directory and the bucket proof stores', function () use ($registry): void {
    expect($registry()->proofStore(Name::of('directory'), Options::none()))
        ->toEqual(LedgerDirectory::at(LedgerDirectory::PATH))
        ->and($registry()->proofStore(Name::of('s3'), Options::ofJson('{"bucket": "ledgers"}')))
        ->toBeInstanceOf(BucketLedger::class);
});

it('registers the cost model that learns from every shard', function () use ($registry): void {
    expect($registry()->costModel(Name::of('learned'), Options::none()))->toBeInstanceOf(MeasuredCosts::class);
});

it('registers a CI plan for each CI it knows, and plain JSON', function () use ($registry): void {
    $plan = static fn(string $name): object => $registry()->ciPlan(Name::of($name), Options::none());

    expect($plan('github'))->toBeInstanceOf(GitHubPlan::class)
        ->and($plan('gitlab'))->toBeInstanceOf(GitLabPlan::class)
        ->and($plan('buildkite'))->toBeInstanceOf(BuildkitePlan::class)
        ->and($plan('circleci'))->toBeInstanceOf(CircleCiPlan::class)
        ->and($plan('json'))->toBeInstanceOf(JsonPlan::class);
});
