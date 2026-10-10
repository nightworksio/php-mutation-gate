<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

$makeRoles = static fn(): FileRoles => FileRoles::of(
    Layout::standard(Paths::of(Path::of('tests/Pest.php'))),
    Packages::of(Trees::of(
        Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())),
        Tree::at(Path::of('packages/billing/src'), Floor::of(0), Package::at(Path::of('packages/billing'))),
    )),
    Paths::of(Path::of('src/helpers.php'), Path::of('packages/billing/src/functions.php')),
);

it('says a change to what the layout names, or to a package\'s lock file, decides how the gate runs', function (string $file) use ($makeRoles): void {
    $roles = $makeRoles();

    expect($roles->decides(Path::of($file)))->toBeTrue();
})->with(['composer.json', 'composer.lock', 'packages/billing/composer.lock', 'packages/billing/composer.json', 'mutation-gate.php', 'phpunit.xml', 'tests/Pest.php']);

it('says no other file decides how the gate runs', function (string $file) use ($makeRoles): void {
    $roles = $makeRoles();

    expect($roles->decides(Path::of($file)))->toBeFalse();
})->with(['src/Money.php', 'README.md', 'config/services.yaml', 'packages/composer.lock', 'src/composer.lock']);

it('says which files Composer\'s autoloader loads in every process', function (string $file, bool $loaded) use ($makeRoles): void {
    $roles = $makeRoles();

    expect($roles->isLoadedEverywhere(Path::of($file)))->toBe($loaded);
})->with([
    'the root package\'s' => ['src/helpers.php', true],
    'another package\'s' => ['packages/billing/src/functions.php', true],
    'another file of the same name' => ['packages/billing/src/helpers.php', false],
    'source' => ['src/Money.php', false],
]);
