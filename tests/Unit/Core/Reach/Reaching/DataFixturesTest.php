<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Tree as Checkout;

/** The directories of this checkout whose PHP files are read for who uses a fixture of tests/Fixtures. */
const DATA_FIXTURES_READ = ['tests/Fixtures', 'tests/Support', 'tests/Unit/Adapter/Codeception', 'tests/Unit/Cli/Flow'];

/** The PHP files of this checkout under DATA_FIXTURES_READ, as they stand. */
function dataFixtureSources(): Sources
{
    $sources = Sources::none();

    foreach (DATA_FIXTURES_READ as $directory) {
        $found = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Checkout::at($directory), FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );

        foreach (array_keys(iterator_to_array($found, preserve_keys: true)) as $absolute) {
            $sources = $sources->withNow(
                Path::of(substr((string) $absolute, strlen(Checkout::root()) + 1)),
                Contents::of((string) file_get_contents((string) $absolute)),
            );
        }
    }

    return $sources;
}

/** This checkout's tests directory, with the fixture projects the gate's own config names. */
function dataFixtureLayout(): Layout
{
    $layout = Layout::standard(Paths::of(Path::of('tests/Pest.php')))->testedIn(SuiteDirectory::of(Path::of('tests'), 'Test.php'));

    foreach (Configs::settings((string) file_get_contents(Checkout::at('mutation-gate.json')))->reach()->fixtures() as $glob) {
        $layout = $layout->withFixtures($glob);
    }

    return $layout;
}

/**
 * The tests of this checkout that use a fixture project of tests/Fixtures,
 * under the gate's own `reach.fixtures`.
 *
 * @return list<string>|string the tests, or why every unit is reached
 */
function dataFixtureUsers(string $fixture): array|string
{
    $layout = dataFixtureLayout();
    $packages = Packages::of(Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))));
    $users = SupportUsers::in($layout, $packages, dataFixtureSources())->fixtures($layout)->of(Path::of($fixture), 'the project');

    return $users instanceof Paths ? array_map(static fn(Path $test): string => $test->value(), [...$users]) : $users->text();
}

it('reaches, for a change to the class fixture HeldCest, the tests that use its class or the support that names it', function (): void {
    expect(dataFixtureUsers('tests/Fixtures/Codeception'))->toEqualCanonicalizing([
        'tests/Unit/Adapter/Codeception/CoveringTest.php',
        'tests/Unit/Adapter/Codeception/ExtensionTest.php',
        'tests/Unit/Adapter/Codeception/TestTest.php',
        'tests/Unit/Adapter/Codeception/ToldTest.php',
    ]);
});

it('reaches, for a change to a workflow of tests/Fixtures/Workflows, the test that reads it by its path', function (): void {
    expect(dataFixtureUsers('tests/Fixtures/Workflows'))->toBe(['tests/Unit/Cli/Flow/ReachedTest.php'])
        ->and(Glob::of('tests/Fixtures/*')->matches(Path::of('tests/Fixtures/Workflows')))->toBeTrue();
});
