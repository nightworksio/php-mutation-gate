<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('keeps the variables and the definitions it is given', function (): void {
    $variables = Variables::of(['CI' => 'true']);
    $job = CiJob::of($variables, Paths::of(Path::of('ci.yml')));

    expect($job->variables())->toBe($variables)
        ->and($job->definitions())->toEqual(Paths::of(Path::of('ci.yml')));
});

it('plans the job run from the pipeline a plan\'s options name as its definition, or says why they name none', function (): void {
    $variables = Variables::of(['SHARD' => '1']);
    $definedIn = static fn(string $options): CiJob|Invalid => CiJob::planned(
        Configs::options($options),
        $variables,
        static fn(CiJob $job): CiJob => $job,
    );

    expect($definedIn('{"definition": "ci/bitbucket.yml"}'))->toEqual(CiJob::of($variables, Paths::of(Path::of('ci/bitbucket.yml'))))
        ->and($definedIn('{"definition": 3}'))->toEqual(Invalid::because(Problem::at('definition', 'expected a path, got 3')))
        ->and($definedIn('{}'))->toEqual(Invalid::because(Problem::at('definition', 'expected the pipeline that runs the gate, as a path')));
});
