<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

/** The plan for a pipeline run from azure-pipelines.yml, in a job with these variables. */
function azurePlanIn(Variables $variables): AzurePlan
{
    return AzurePlan::printing('', $variables, Path::of('azure-pipelines.yml'));
}

/** The reason Azure DevOps gives the plan for naming no default branch. */
function azureUnnamed(): CannotTell
{
    return CannotTell::because('Azure DevOps does not name the default branch. Set ci.defaultBranch.');
}

it('sets the output variable the mutation job\'s matrix reads, one leg per shard', function (): void {
    $file = sprintf('%s/matrix.txt', Scratch::directory());
    $written = AzurePlan::printing($file, Variables::of([]), Path::of('azure-pipelines.yml'))->publish(ShardedPlan::of(2));

    expect($written)->toEqual(Written::to($file))
        ->and(file_get_contents($file))
        ->toBe("##vso[task.setvariable variable=matrix;isOutput=true]{\"s1\":{\"SHARD\":\"1\"},\"s2\":{\"SHARD\":\"2\"}}\n");
});

it('sets one leg with no shard for a plan that holds none, as Azure always makes a job', function (): void {
    $file = sprintf('%s/matrix.txt', Scratch::directory());
    AzurePlan::printing($file, Variables::of([]), Path::of('azure-pipelines.yml'))->publish(ShardedPlan::of(0));

    expect(file_get_contents($file))->toBe("##vso[task.setvariable variable=matrix;isOutput=true]{\"none\":{\"SHARD\":\"\"}}\n");
});

it('prints to the output, where the agent reads its logging commands', function (): void {
    ob_start();
    $plan = AzurePlan::fromOptions(Configs::options('{"definition": "azure-pipelines.yml"}'));
    $written = $plan instanceof AzurePlan ? $plan->publish(ShardedPlan::of(1)) : $plan;
    $printed = ob_get_clean();

    expect($written)->toEqual(Written::to('php://output'))
        ->and($printed)->toBe("##vso[task.setvariable variable=matrix;isOutput=true]{\"s1\":{\"SHARD\":\"1\"}}\n");
});

it('cannot judge a plan it cannot print', function (): void {
    $root = Scratch::directory();
    $azure = AzurePlan::printing(sprintf('%s/missing/matrix.txt', $root), Variables::of([]), Path::of('azure-pipelines.yml'));
    set_error_handler(static fn(): bool => true);
    $written = $azure->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/missing/matrix.txt could not be written.', $root)));
});

it('runs the shard its matrix leg names', function (): void {
    expect(azurePlanIn(Variables::of(['SHARD' => '2']))->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(2));
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
