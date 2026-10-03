<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;

it('names its shard of a plan by the variables its CI set', function (): void {
    $job = CiJob::of(Variables::of(['SHARD' => '2']), Paths::none());

    expect($job->shard(ShardedPlan::of(2)))->toEqual(ShardId::of(2))
        ->and(CiJob::of(Variables::of([]), Paths::none())->shard(ShardedPlan::of(2)))->toBeInstanceOf(CannotJudge::class);
});

it('keeps the variables and the definitions it is given', function (): void {
    $variables = Variables::of(['CI' => 'true']);
    $job = CiJob::of($variables, Paths::of(Path::of('ci.yml')));

    expect($job->variables())->toBe($variables)
        ->and($job->definitions())->toEqual(Paths::of(Path::of('ci.yml')));
});

it('is run from the pipeline a plan\'s options name as its definition, or says why they name none', function (): void {
    $definedIn = static fn(string $options): CiJob|Invalid => CiJob::definedIn(Configs::options($options), Variables::of([]));
    $job = $definedIn('{"definition": "ci/bitbucket.yml"}');

    expect($job instanceof CiJob ? $job->definitions() : $job)->toEqual(Paths::of(Path::of('ci/bitbucket.yml')))
        ->and($definedIn('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got 3')))
        ->and($definedIn('{}'))->toEqual(Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path')));
});
