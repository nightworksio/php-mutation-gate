<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Locks;

$makeRoles = static fn(): FileRoles => FileRoles::of(
    Layout::standard(Paths::of(Path::of('tests/Pest.php'))),
    Packages::of(Trees::of(
        Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())),
        Tree::at(Path::of('packages/billing/src'), Floor::of(0), Package::at(Path::of('packages/billing'))),
    )),
    Paths::of(Path::of('src/helpers.php'), Path::of('packages/billing/src/functions.php')),
    DecidingPackages::named(Glob::of('pestphp/*')),
);

$lock = static fn(string $pest, string $console): Contents => Locks::of(['pestphp/pest' => $pest, 'symfony/console' => $console]);

it('says a change to what the layout names, or to a package\'s lock file, decides how the gate runs', function (string $file) use ($makeRoles): void {
    $roles = $makeRoles();

    expect($roles->decides(Path::of($file), Missing::at(Path::of($file)), Missing::at(Path::of($file))))->toBeTrue();
})->with(['composer.json', 'composer.lock', 'packages/billing/composer.lock', 'packages/billing/composer.json', 'mutation-gate.php', 'phpunit.xml', 'tests/Pest.php']);

it('says a package\'s lock decides where its change moved a package the config names, and not where it moved only others', function (string $file, string $pest, bool $decides) use ($makeRoles, $lock): void {
    $roles = $makeRoles();

    expect($roles->isLock(Path::of($file)))->toBeTrue()
        ->and($roles->decides(Path::of($file), $lock('5.2.1', '8.1.7'), $lock($pest, '8.1.9')))->toBe($decides);
})->with([
    'the root\'s, moving pest' => ['composer.lock', '5.2.2', true],
    'the root\'s, moving only the console' => ['composer.lock', '5.2.1', false],
    'a package\'s, moving pest' => ['packages/billing/composer.lock', '5.2.2', true],
    'a package\'s, moving only the console' => ['packages/billing/composer.lock', '5.2.1', false],
]);

it('says no other file decides how the gate runs', function (string $file) use ($makeRoles): void {
    $roles = $makeRoles();

    expect($roles->decides(Path::of($file), Missing::at(Path::of($file)), Missing::at(Path::of($file))))->toBeFalse()
        ->and($roles->isLock(Path::of($file)))->toBeFalse();
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

it('says every lock decides, whatever it moved, where the config reads a file it cannot name', function () use ($lock): void {
    $roles = FileRoles::of(
        Layout::standard(Paths::none())->readByTheConfig(ConfigReads::unnamed('The config builds a path as it runs.')),
        Packages::of(Trees::of(Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())))),
        Paths::none(),
        DecidingPackages::named(Glob::of('pestphp/*')),
    );

    expect($roles->decides(Path::of('composer.lock'), $lock('5.2.1', '8.1.7'), $lock('5.2.1', '8.1.9')))->toBeTrue();
});
