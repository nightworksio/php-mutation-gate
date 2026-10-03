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

$roles = FileRoles::of(
    Layout::standard(Paths::of(Path::of('tests/Pest.php'))),
    Packages::of(Trees::of(
        Tree::at(Path::of('src'), Floor::of(0), Package::at(Path::root())),
        Tree::at(Path::of('packages/billing/src'), Floor::of(0), Package::at(Path::of('packages/billing'))),
    )),
);

it('says a change to what the layout names, or to a package\'s lock file, decides how the gate runs', function (string $file) use ($roles): void {
    expect($roles->decides(Path::of($file)))->toBeTrue();
})->with(['composer.json', 'composer.lock', 'packages/billing/composer.lock', 'packages/billing/composer.json', 'mutation-gate.php', 'phpunit.xml', 'tests/Pest.php']);

it('says no other file decides how the gate runs', function (string $file) use ($roles): void {
    expect($roles->decides(Path::of($file)))->toBeFalse();
})->with(['src/Money.php', 'README.md', 'config/services.yaml', 'packages/composer.lock', 'src/composer.lock']);

it('says which PHP files are of a package\'s tests, whether they are there or not', function (string $file, bool $tested) use ($roles): void {
    expect($roles->isTested(Path::of($file)))->toBe($tested);
})->with([
    'a file of test cases' => ['tests/MoneyTest.php', true],
    'support' => ['tests/Support/Builder.php', true],
    'another package\'s test cases' => ['packages/billing/tests/InvoiceTest.php', true],
    'a fixture that is not PHP' => ['tests/fixtures/rates.json', false],
    'source' => ['src/Money.php', false],
    'a package\'s source' => ['packages/billing/src/Invoice.php', false],
]);
