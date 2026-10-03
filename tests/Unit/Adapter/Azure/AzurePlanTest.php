<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

/** The plan for a pipeline run from azure-pipelines.yml, in a job with these variables. */
function azurePlanIn(Variables $variables): AzurePlan
{
    return AzurePlan::in(CiJob::of($variables, Paths::of(Path::of('azure-pipelines.yml'))));
}

/** The reason Azure DevOps gives the plan for naming no default branch. */
function azureUnnamed(): CannotTell
{
    return CannotTell::because('Azure DevOps does not name the default branch. Set ci.defaultBranch.');
}

it('sets the output variable the mutation job\'s matrix reads, one leg per shard', function (): void {
    expect(azurePlanIn(Variables::of([]))->publish(ShardedPlan::of(2)))->toEqual(Publication::printed(
        "##vso[task.setvariable variable=matrix;isOutput=true]{\"s1\":{\"SHARD\":\"1\"},\"s2\":{\"SHARD\":\"2\"}}\n",
    ));
});

it('sets one leg with no shard for a plan that holds none, as Azure always makes a job', function (): void {
    expect(azurePlanIn(Variables::of([]))->publish(ShardedPlan::of(0))->text())
        ->toBe("##vso[task.setvariable variable=matrix;isOutput=true]{\"none\":{\"SHARD\":\"\"}}\n");
});

it('prints to the output, where the agent reads its logging commands', function (): void {
    $plan = AzurePlan::fromOptions(Configs::options('{"definition": "azure-pipelines.yml"}'));

    expect($plan instanceof AzurePlan ? $plan->publish(ShardedPlan::of(1)) : $plan)->toEqual(Publication::printed(
        "##vso[task.setvariable variable=matrix;isOutput=true]{\"s1\":{\"SHARD\":\"1\"}}\n",
    ));
});

it('reads a pull request from its id where the build is for one, and the ref it builds otherwise', function (): void {
    $pullRequest = Variables::of([
        'BUILD_REASON' => 'PullRequest',
        'BUILD_SOURCEBRANCH' => 'refs/pull/31/merge',
        'SYSTEM_PULLREQUEST_PULLREQUESTID' => '31',
    ]);
    $push = Variables::of(['BUILD_REASON' => 'IndividualCI', 'BUILD_SOURCEBRANCH' => 'refs/heads/feature/money']);

    expect(azurePlanIn($pullRequest)->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('31'), azureUnnamed()))
        ->and(azurePlanIn($push)->runOn())->toEqual(RunOn::at(Scope::branch('feature/money'), azureUnnamed()));
});

it('reads a GitHub pull request by its number, which Azure sets apart from its id', function (): void {
    $github = Variables::of([
        'BUILD_REASON' => 'PullRequest',
        'SYSTEM_PULLREQUEST_PULLREQUESTID' => '1742905561',
        'SYSTEM_PULLREQUEST_PULLREQUESTNUMBER' => '31',
    ]);

    expect(azurePlanIn($github)->runOn())->toEqual(RunOn::pullRequest(PullRequestNumber::parse('31'), azureUnnamed()));
});

it('gives no scope to a tag, which is no branch the gate writes for', function (): void {
    expect(azurePlanIn(Variables::of(['BUILD_REASON' => 'Manual', 'BUILD_SOURCEBRANCH' => 'refs/tags/v1']))->runOn())
        ->toEqual(RunOn::detached(azureUnnamed()));
});

it('is run by the pipeline file the config names', function (): void {
    $definitions = static function (string $options): Paths|Invalid {
        $plan = AzurePlan::fromOptions(Configs::options($options));

        return $plan instanceof AzurePlan ? $plan->definitions() : $plan;
    };
    $missing = Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path'));

    expect($definitions('{"definition": ".azure/mutation-gate.yml"}'))->toEqual(Paths::of(Path::of('.azure/mutation-gate.yml')))
        ->and($definitions(Ci::none()->planOptions(Name::of('azure'))->written()->line()))
        ->toEqual(Paths::of(Path::of('azure-pipelines.yml')))
        ->and($definitions('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got 3')))
        ->and($definitions('{"definition": ""}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got ""')))
        ->and($definitions('{}'))->toEqual($missing);
});

it('withholds the job\'s access token, and the personal access token az devops reads', function (): void {
    expect(AzurePlan::withheld())->toEqual(Withheld::of('SYSTEM_ACCESSTOKEN', 'AZURE_DEVOPS_EXT_PAT'));
});

it('is marked by TF_BUILD, which Azure Pipelines sets in every job', function (): void {
    expect(AzurePlan::marker())->toEqual(CiMarker::setting('TF_BUILD'));
});
