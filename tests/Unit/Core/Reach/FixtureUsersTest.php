<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Reach\FixtureUsers;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\SupportUsers;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree as SourceTree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Tree;

/**
 * Who uses each fixture project under `tests/Runner` among these files, by path, in a project of one tree.
 *
 * @param array<string, string> $files
 */
function fixtureUsersAmong(array $files): FixtureUsers
{
    $sources = Sources::none();

    foreach ($files as $path => $text) {
        $sources = $sources->withNow(Path::of($path), Contents::of($text));
    }

    $layout = Layout::standard(Paths::of(Path::of('tests/Pest.php')))->withFixtures(Glob::of('tests/Runner/*-fixture'));

    return SupportUsers::in(
        $layout,
        Packages::of(Trees::of(SourceTree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())))),
        $sources,
    )->fixtures($layout);
}

/** @return list<string>|Reason */
function fixtureUsersOf(FixtureUsers $users, string $fixture): array|Reason
{
    $found = $users->of(Path::of($fixture), 'the project');

    return $found instanceof Paths ? array_map(static fn(Path $test): string => $test->value(), [...$found]) : $found;
}

$spec = "<?php\n\nit('splits', fn () => true);\n";

it('finds each test that names the fixture\'s path or a path inside it, spelt from the root or from its own directory', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => $spec,
        'tests/Runner/HeavyTest.php' => "<?php\n\nit('splits', fn () => copy(sprintf('tests/Runner/heavy-fixture/%s', \$file), \$to));\n",
        'tests/Runner/LocalTest.php' => "<?php\n\nit('reads', fn () => file_get_contents(__DIR__ . '/heavy-fixture/composer.json'));\n",
        'tests/Runner/WholeTest.php' => "<?php\n\nit('copies', fn () => Tree::copy('./tests/Runner/heavy-fixture'));\n",
        'tests/Runner/Deep/UpTest.php' => "<?php\n\nit('climbs', fn () => __DIR__ . '/../heavy-fixture/src/Split.php');\n",
        'tests/Runner/OtherTest.php' => "<?php\n\nit('copies', fn () => Tree::copy('tests/Runner/heavy-fixtures/src'));\n",
        'tests/Unit/HeavyTest.php' => "<?php\n\nit('weighs', fn () => 'heavy-fixture');\n",
        'tests/Runner/NamedTest.php' => "<?php\n\nit('weighs a heavy-fixture project', fn () => 'tests/Runner');\n",
        'tests/Runner/RootedTest.php' => "<?php\n\nit('roots', fn () => base_path('/tests/Runner/heavy-fixture'));\n",
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))->toBe([
        'tests/Runner/HeavyTest.php',
        'tests/Runner/LocalTest.php',
        'tests/Runner/WholeTest.php',
        'tests/Runner/Deep/UpTest.php',
        'tests/Runner/RootedTest.php',
    ]);
});

it('finds the tests that use support whose strings name the fixture, through the support that names that in turn', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => $spec,
        'tests/Runner/Library.php' => "<?php\n\nnamespace Tests\\Runner;\n\nfinal class Library { public const string DIRECTORY = 'tests/Runner/heavy-fixture'; }\n",
        'tests/Runner/Shelf.php' => "<?php\n\nnamespace Tests\\Runner;\n\nfinal class Shelf { public function of(Library \$library): void {} }\n",
        'tests/Runner/InstallTest.php' => "<?php\n\nuse Tests\\Runner\\Library;\n\nit('installs', fn () => Library::DIRECTORY);\n",
        'tests/Runner/ShelfTest.php' => "<?php\n\nuse Tests\\Runner\\Shelf;\n\nit('shelves', fn () => new Shelf());\n",
        'tests/Runner/MoneyTest.php' => "<?php\n\nit('adds', fn () => 1);\n",
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))->toBe(['tests/Runner/InstallTest.php', 'tests/Runner/ShelfTest.php']);
});

it('finds the tests that use another fixture one of whose files names the fixture, following each fixture once', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/base-fixture/src/Base.php' => "<?php\n\nnamespace Base;\n\nfinal class Base {}\n",
        'tests/Runner/app-fixture/composer.php' => "<?php\n\nreturn ['repositories' => ['../base-fixture', 'tests/Runner/app-fixture']];\n",
        'tests/Runner/base-fixture/composer.php' => "<?php\n\nreturn ['repositories' => ['tests/Runner/app-fixture']];\n",
        'tests/Runner/AppTest.php' => "<?php\n\nit('boots', fn () => 'tests/Runner/app-fixture/src');\n",
        'tests/Runner/BaseTest.php' => $spec,
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/base-fixture'))->toBe(['tests/Runner/AppTest.php'])
        ->and(fixtureUsersOf($users, 'tests/Runner/app-fixture'))->toBe(['tests/Runner/AppTest.php']);
});

it('follows every fixture that names the fixture, in the order it finds them, each to the tests that name it', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/base-fixture/src/Base.php' => "<?php\n\nnamespace Base;\n\nfinal class Base {}\n",
        'tests/Runner/app-fixture/composer.php' => "<?php\n\nreturn ['repositories' => ['../base-fixture']];\n",
        'tests/Runner/cli-fixture/composer.php' => "<?php\n\nreturn ['repositories' => ['../base-fixture']];\n",
        'tests/Runner/AppTest.php' => "<?php\n\nit('boots', fn () => 'tests/Runner/app-fixture/src');\n",
        'tests/Runner/CliTest.php' => "<?php\n\nit('runs', fn () => 'tests/Runner/cli-fixture/bin');\n",
        'tests/Runner/BaseTest.php' => $spec,
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/base-fixture'))->toBe(['tests/Runner/AppTest.php', 'tests/Runner/CliTest.php']);
});

it('reaches the whole package where no test outside the fixture is found to name its path, its own files aside', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => "<?php\n\nit('reads itself', fn () => 'tests/Runner/heavy-fixture/src');\n",
        'tests/Runner/heavy-fixture/vendor/LibraryTest.php' => "<?php\n\nit('loads', fn () => 'tests/Runner/heavy-fixture');\n",
        'tests/Runner/MoneyTest.php' => $spec,
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))->toEqual(Reason::that(
        'No test is found to name the fixture `tests/Runner/heavy-fixture`, so what reads it cannot be told: every unit of the project is reached.',
    ));
});

it('finds a test that spells the fixture\'s path from a directory above its own, as dirname(__DIR__, n) does', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => $spec,
        'tests/Unit/Runner/Deep/SplitTest.php' => "<?php\n\nit('splits', fn () => dirname(__DIR__, 3) . '/Runner/heavy-fixture/composer.json');\n",
        'tests/Unit/Other/OtherTest.php' => "<?php\n\nit('splits', fn () => dirname(__DIR__) . '/Runner/heavy-fixtures');\n",
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))->toBe(['tests/Unit/Runner/Deep/SplitTest.php']);
});

it('reaches the whole package where support that runs code when it is loaded names the fixture', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => $spec,
        'tests/Datasets/Fixtures.php' => "<?php\n\ndataset('fixtures', ['tests/Runner/heavy-fixture']);\n",
        'tests/Runner/HeavyTest.php' => "<?php\n\nit('splits', fn () => 'tests/Runner/heavy-fixture/src');\n",
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))
        ->toEqual(Reason::that('`tests/Datasets/Fixtures.php` runs code when it is loaded, so every unit of the project is reached.'));
});

it('reaches the whole package where a file that is no test names the fixture', function () use ($spec): void {
    $users = fixtureUsersAmong([
        'tests/Runner/heavy-fixture/tests/SplitSpec.php' => $spec,
        'src/Wiring.php' => "<?php\n\nnamespace App;\n\nfinal class Wiring { public const string FIXTURE = 'tests/Runner/heavy-fixture'; }\n",
    ]);

    expect(fixtureUsersOf($users, 'tests/Runner/heavy-fixture'))
        ->toEqual(Reason::that('`src/Wiring.php` names the fixture `tests/Runner/heavy-fixture` and is no test, so every unit of the project is reached.'));
});

it('lists in the gate\'s own config every fixture project the PHPUnit config leaves out of its suites, each a directory', function (): void {
    $phpunit = simplexml_load_string((string) file_get_contents(Tree::at('phpunit.xml')));
    $excluded = $phpunit instanceof SimpleXMLElement
        ? array_map(static fn(SimpleXMLElement $exclude): string => (string) $exclude, is_array($phpunit->xpath('//testsuite/exclude')) ? $phpunit->xpath('//testsuite/exclude') : [])
        : [];
    $listed = array_map(
        static fn(Node $fixture): string => $fixture->text(),
        Node::decode((string) file_get_contents(Tree::at('mutation-gate.json')))->field('reach')->field('fixtures')->items(),
    );

    expect($listed)->toEqualCanonicalizing($excluded)
        ->and(array_filter($listed, static fn(string $fixture): bool => ! is_dir(Tree::at($fixture))))->toBe([]);
});
