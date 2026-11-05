<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('holds each definition\'s text by its path, and names its paths', function (): void {
    $definitions = GateDefinitions::none()->with(Path::of('.gitlab-ci.yml'), 'a')->with(Path::of('bitbucket-pipelines.yml'), 'b');

    expect([...$definitions])->toBe(['.gitlab-ci.yml' => 'a', 'bitbucket-pipelines.yml' => 'b'])
        ->and($definitions->paths())->toEqual(Paths::of(Path::of('.gitlab-ci.yml'), Path::of('bitbucket-pipelines.yml')))
        ->and([...GateDefinitions::none()])->toBe([]);
});
