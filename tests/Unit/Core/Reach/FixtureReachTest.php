<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\FixtureReach;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * What these changes reach through the fixture project `tests/fixtures/shop`,
 * which `tests/Unit/CartTest.php` and the shop module's `ShopTest.php` name,
 * under this coverage.
 */
function fixtureReached(Changes $changes, Judges|NoMap $coverage): Reach
{
    $layout = Layout::standard(Paths::of(Path::of('tests/Pest.php')))
        ->testedIn(SuiteDirectory::conventional(), SuiteDirectory::of(Path::of('modules/shop/tests'), 'Test.php'))
        ->withFixtures(Glob::of('tests/fixtures/*'))
        ->withModule(Path::of('modules/shop'));
    $trees = Trees::of(
        Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
        Tree::at(Path::of('modules/shop/src'), Undeclared::floor(), Package::at(Path::root())),
    );
    $sources = Sources::none()
        ->withNow(Path::of('tests/Unit/CartTest.php'), Contents::of("<?php\n\nit('carts', fn () => 'tests/fixtures/shop/cart.json');\n"))
        ->withNow(Path::of('modules/shop/tests/ShopTest.php'), Contents::of("<?php\n\nit('sells', fn () => 'tests/fixtures/shop');\n"));
    $packages = Packages::of($trees);
    $fixtures = FixtureReach::of($layout, $trees, $coverage, SupportUsers::in($layout, $packages, $sources), $changes);
    $reach = Reach::nothing($packages);

    foreach ($changes as $change) {
        $reach = $fixtures->reach($reach, $change);
    }

    return $reach;
}

/** @return list<string> */
function fixtureSaid(Reach $reach): array
{
    return array_map(static fn(Reason $reason): string => $reason->text(), [...$reach->reasons()]);
}

it('reaches what the users of a changed fixture run, and every tree of the module one of them is in', function (): void {
    $reach = fixtureReached(
        Changes::of(Change::modified(Path::of('tests/fixtures/shop/cart.json'), Lines::none())),
        Judges::none()->judging(Path::of('src/Cart.php'), Paths::of(Path::of('tests/Unit/CartTest.php'))),
    );

    expect($reach->reaches(Unit::file(Path::of('src/Cart.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('modules/shop/src/Shop.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeFalse()
        ->and(fixtureSaid($reach))->toBe([
            '`modules/shop/tests/ShopTest.php` is a test of the module modules/shop, so every tree of it is reached.',
            '`tests/fixtures/shop/cart.json` is in the fixture `tests/fixtures/shop`, which 2 tests use, so the 1 files they run are reached.',
        ]);
});

it('reaches every unit of the package where no map says what the users of a changed fixture run', function (): void {
    $reach = fixtureReached(Changes::of(Change::modified(Path::of('tests/fixtures/shop/cart.json'), Lines::none())), NoMap::toRead());

    expect($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeTrue()
        ->and(fixtureSaid($reach))->toBe(['No coverage map says what `tests/fixtures/shop/cart.json` runs, so every unit of the project is reached.']);
});

it('reaches nothing through a change outside every fixture project', function (): void {
    $reach = fixtureReached(Changes::of(Change::modified(Path::of('tests/Unit/CartTest.php'), Lines::none())), Judges::none());

    expect(fixtureSaid($reach))->toBe([])
        ->and($reach->reaches(Unit::file(Path::of('src/Cart.php'))))->toBeFalse();
});
