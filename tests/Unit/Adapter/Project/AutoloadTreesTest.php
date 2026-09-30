<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\AutoloadTrees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot judge a project whose composer.json autoloads no path', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"autoload-dev": {"psr-4": {"Tests\\\\": "tests/"}}}');

    expect(AutoloadTrees::in($project)->trees())
        ->toEqual(CannotJudge::because('No tree found: composer.json autoloads no path; list trees in the config.'));
});

it('passes on a composer.json it cannot read', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '3');

    expect(AutoloadTrees::in($project)->trees())->toEqual(CannotJudge::because('composer.json is not a JSON object.'));
});
