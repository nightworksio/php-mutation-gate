<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\SupportPlaces;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('reads the root\'s support places where no tree is in the root\'s package', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"autoload-dev": {"psr-4": {"Tests\\\\": "support/"}}}');
    Scratch::write($project, 'packages/a/composer.json', '{"autoload-dev": {"psr-4": {"A\\\\Tests\\\\": "fakes/"}}}');

    $places = SupportPlaces::read(
        Directory::at($project),
        Trees::of(Tree::at(Path::of('packages/a/src'), Floor::of(50), Package::at(Path::of('packages/a')))),
    );

    expect($places->inPackages())->toEqual(Paths::of(Path::of('support'), Path::of('fakes')));
});
