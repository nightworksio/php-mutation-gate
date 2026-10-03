<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Bitbucket\BitbucketPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
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
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\LogCommands;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

afterEach(function (): void {
    Scratch::sweep();
});

/** The plan for a pipeline run from bitbucket-pipelines.yml, in a step with these variables. */
function bitbucketPlanIn(Variables $variables): BitbucketPlan
{
    return BitbucketPlan::printing('', $variables, Path::of('bitbucket-pipelines.yml'));
}

/** The reason Bitbucket gives the plan for naming no default branch. */
function bitbucketUnnamed(): CannotTell
{
    return CannotTell::because('Bitbucket does not name the default branch. Set ci.defaultBranch.');
}

it('prints the plan as the generic JSON', function (): void {
    $file = sprintf('%s/plan.json', Scratch::directory());
    $written = BitbucketPlan::printing($file, Variables::of([]), Path::of('bitbucket-pipelines.yml'))
        ->publish(ShardedPlan::of(2));

    expect($written)->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(PlanListing::of(ShardedPlan::of(2)));
});

it('prints to the output', function (): void {
    ob_start();
    $plan = BitbucketPlan::fromOptions(Configs::options('{"definition": "bitbucket-pipelines.yml"}'));
    $written = $plan instanceof BitbucketPlan ? $plan->publish(ShardedPlan::of(1)) : $plan;
    $printed = ob_get_clean();

    expect($written)->toEqual(Written::to('php://output'))
        ->and($printed)->toBe(PlanListing::of(ShardedPlan::of(1)));
});

it('cannot judge a plan it cannot print', function (): void {
    $root = Scratch::directory();
    $bitbucket = BitbucketPlan::printing(
        sprintf('%s/missing/plan.json', $root),
        Variables::of([]),
        Path::of('bitbucket-pipelines.yml'),
    );
    set_error_handler(static fn(): bool => true);
    $written = $bitbucket->publish(ShardedPlan::of(1));
    restore_error_handler();

    expect($written)->toEqual(CannotJudge::because(sprintf('%s/missing/plan.json could not be written.', $root)));
});

it('runs the shard after the parallel step\'s index, which counts from nought', function (): void {
    $step = Variables::of(['BITBUCKET_PARALLEL_STEP' => '1', 'BITBUCKET_PARALLEL_STEP_COUNT' => '2']);

    expect(bitbucketPlanIn($step)->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(2));
});

it('cannot judge parallel steps that are not as many as the plan\'s shards', function (): void {
    $step = Variables::of(['BITBUCKET_PARALLEL_STEP' => '0', 'BITBUCKET_PARALLEL_STEP_COUNT' => '4']);

    expect(bitbucketPlanIn($step)->shard(ShardedPlan::of(2)))->toEqual(CannotJudge::because(
        'BITBUCKET_PARALLEL_STEP_COUNT is 4, and the plan holds 2 shards. Plan with --shards=4, so each job has a shard.',
    ));
});

it('reads a pull request from its id, and the branch it builds otherwise', function (): void {
    $pullRequest = Variables::of(['BITBUCKET_BRANCH' => 'feature/money', 'BITBUCKET_PR_ID' => '31']);
    $push = Variables::of(['BITBUCKET_BRANCH' => 'feature/money']);

    expect(bitbucketPlanIn($pullRequest)->runOn())
        ->toEqual(RunOn::pullRequest(PullRequestNumber::parse('31'), bitbucketUnnamed()))
        ->and(bitbucketPlanIn($push)->runOn())->toEqual(RunOn::branch('feature/money', bitbucketUnnamed()));
});

it('gives no scope to a tag, which is no branch the gate writes for', function (): void {
    expect(bitbucketPlanIn(Variables::of(['BITBUCKET_TAG' => 'v1']))->runOn())
        ->toEqual(RunOn::detached(bitbucketUnnamed()));
});

it('is run by the pipeline file the config names', function (): void {
    $definitions = static function (string $options): Paths|Invalid {
        $plan = BitbucketPlan::fromOptions(Configs::options($options));

        return $plan instanceof BitbucketPlan ? $plan->definitions() : $plan;
    };
    $missing = Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path'));

    expect($definitions('{"definition": "ci/bitbucket.yml"}'))->toEqual(Paths::of(Path::of('ci/bitbucket.yml')))
        ->and($definitions(Ci::none()->planOptions(Name::of('bitbucket'))->written()->line()))
        ->toEqual(Paths::of(Path::of('bitbucket-pipelines.yml')))
        ->and($definitions('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got 3')))
        ->and($definitions('{}'))->toEqual($missing);
});

it('withholds the step\'s OpenID Connect token', function (): void {
    expect(BitbucketPlan::withheld())->toEqual(Withheld::of('BITBUCKET_STEP_OIDC_TOKEN'));
});

it('is marked by BITBUCKET_BUILD_NUMBER, which Bitbucket Pipelines sets in every step', function (): void {
    expect(BitbucketPlan::marker())->toEqual(CiMarker::setting('BITBUCKET_BUILD_NUMBER'));
});

it('prints a plan whose paths hold log commands so a CI\'s log reads none, and every reader reads the paths back', function (): void {
    $file = sprintf('%s/plan.json', Scratch::directory());
    BitbucketPlan::printing($file, Variables::of([]), Path::of('bitbucket-pipelines.yml'))->publish(ShardedPlan::hostile());
    $printed = (string) file_get_contents($file);

    expect(LogCommands::in($printed))->toBe([])
        ->and(Decoded::at($printed, 'shards', 0, 'units'))->toBe([ShardedPlan::HOSTILE]);
});
