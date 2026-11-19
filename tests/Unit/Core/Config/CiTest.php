<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

it('writes whether a merged pull request\'s proofs are trusted as the PHP config calls it', function (): void {
    expect([Ci::of(trustMergedPullRequests: true)->php(ProjectRoot::origin()), Ci::of(trustMergedPullRequests: false)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Ci::trustingMergedPullRequests()'), PhpCalls::inWith('Ci::notTrustingMergedPullRequests()')]);
});

it('writes every key it holds as the PHP config calls it, in the order the builder takes them', function (): void {
    $ci = Ci::of(
        plan: Choice::of('gitlab', Options::none()),
        defaultBranch: 'trunk',
        check: 'mutation',
        trustMergedPullRequests: true,
        gitlabTemplate: Path::of('.gitlab/gate.yml'),
        buildkiteStep: BuildkiteStep::of(Json::object(Member::of('soft_fail', value: true))),
        buildkiteDefinition: Path::of('.buildkite/pipeline.yml'),
        azureDefinition: Path::of('azure-pipelines.yml'),
        bitbucketDefinition: Path::of('bitbucket-pipelines.yml'),
        jenkinsDefinition: Path::of('Jenkinsfile'),
    );

    expect($ci->php(ProjectRoot::origin()))->toEqual(PhpCalls::inWith(
        'Ci::gitlab()',
        "Ci::defaultBranch('trunk')",
        "Ci::check('mutation')",
        'Ci::trustingMergedPullRequests()',
        "Pipeline::gitlabTemplate('.gitlab/gate.yml')",
        "Pipeline::buildkiteStep(Option::of('soft_fail', true))",
        "Pipeline::buildkiteDefinition('.buildkite/pipeline.yml')",
        "Pipeline::azureDefinition('azure-pipelines.yml')",
        "Pipeline::bitbucketDefinition('bitbucket-pipelines.yml')",
        "Pipeline::jenkinsDefinition('Jenkinsfile')",
    ));
});
