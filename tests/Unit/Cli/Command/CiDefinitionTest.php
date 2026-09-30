<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\CiDefinition;
use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('says a template the package is missing is to be reinstalled, and why one it cannot read cannot be', function (
    string $templates,
    string $why,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'templates/circleci/config.yml/unreadable', '');
    $definition = new CiDefinition(
        $project,
        new Extensions(Origin::of('acme/gate')),
        GatePin::unknown(),
        Directory::at(sprintf('%s/%s', $project, $templates)),
    );
    $made = $definition->made(
        CiRequest::of(BuiltinCiPlan::CircleCi, NotGiven::value(), Output::Written),
        Configs::settings(['runner' => 'pest']),
    );

    expect($made instanceof CannotJudge ? $made->why() : $made)->toBe(sprintf($why, $project));
})->with([
    'missing' => ['nowhere', 'The template circleci/config.yml is missing from the package. Reinstall it.'],
    'a directory' => ['templates', '%s/templates/circleci/config.yml could not be read.'],
]);
