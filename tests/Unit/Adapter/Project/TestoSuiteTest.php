<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\TestoSuite;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

/** The suite a testo.php of this code declares, its uses of Testo's config classes written ahead of it. */
function testoSuite(string $code): TestSuite|CannotJudge
{
    $config = sprintf(<<<'PHP'
        <?php

        use Testo\Application\Config\ApplicationConfig;
        use Testo\Application\Config\FinderConfig;
        use Testo\Application\Config\SuiteConfig;

        %s
        PHP, $code);

    return TestoSuite::declaredIn(Contents::of($config), Path::of('testo.php'));
}

it('keeps the tests in tests, in every PHP file under it, where nothing configures them', function (): void {
    $suite = TestoSuite::conventional();

    expect($suite->paths())->toEqual(Paths::of(Path::of('tests')))
        ->and($suite->conventionalDirectory())->toEqual(SuiteDirectory::of(Path::of('tests'), '.php'))
        ->and($suite->holdsTestCase(Path::of('tests/Unit/Money.php')))->toBeTrue()
        ->and($suite->holdsTestCase(Path::of('src/Money.php')))->toBeFalse();
});

it('keeps each suite\'s directories and files, named or in their place, and none of a suite by its name', function (): void {
    $suite = testoSuite(<<<'PHP'
        return new ApplicationConfig(
            src: ['src'],
            suites: [
                new SuiteConfig(name: 'unit', location: ['tests/Unit', 'checks/MoneyTest.php']),
                new SuiteConfig('stock', ['plugins/stock/tests']),
            ],
        );
        PHP);

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)
        ->toEqual(Paths::of(Path::of('tests/Unit'), Path::of('plugins/stock/tests')))
        ->and($suite instanceof TestSuite ? $suite->suites() : $suite)->toEqual(DeclaredSuites::none())
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/MoneyTest.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('plugins/stock/tests/StockTest.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/LedgerTest.php')))->toBeFalse();
});

it('keeps what a finder includes, less what it leaves out, by name or in place', function (): void {
    $named = testoSuite("return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: new FinderConfig(include: ['tests'], exclude: ['tests/Fixtures']))]);");
    $placed = testoSuite("return new ApplicationConfig(suites: [new SuiteConfig('unit', new FinderConfig(['tests'], ['tests/Fixtures']))]);");

    foreach ([$named, $placed] as $suite) {
        expect($suite instanceof TestSuite ? $suite->paths() : $suite)->toEqual(Paths::of(Path::of('tests')))
            ->and($suite instanceof TestSuite && $suite->holds(Path::of('tests/MoneyTest.php')))->toBeTrue()
            ->and($suite instanceof TestSuite && $suite->holds(Path::of('tests/Fixtures/Money.php')))->toBeFalse();
    }
});

it('reads __DIR__ as the root, alone or with a literal path after it', function (): void {
    $suite = testoSuite("return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: [__DIR__ . '/tests', __DIR__])]);");

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)->toEqual(Paths::of(Path::of('tests'), Path::root()));
});

it('keeps Testo\'s default suite where the config makes no suite, and the conventional one with no location', function (): void {
    $default = testoSuite("return new ApplicationConfig(src: ['src']);");
    $unlocated = testoSuite("return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit')]);");

    expect($default instanceof TestSuite ? $default->paths() : $default)->toEqual(Paths::of(Path::of('tests')))
        ->and($unlocated instanceof TestSuite ? $unlocated->paths() : $unlocated)->toEqual(Paths::of(Path::of('tests')));
});

it('cannot read the tests of a config that builds its suites or their locations as it runs, naming the line', function (string $code, int $line): void {
    $suite = testoSuite($code);

    expect($suite instanceof CannotJudge ? $suite->why() : $suite)->toBe(sprintf(
        "testo.php builds its suites' locations as it runs (line %d), so the gate cannot read them. Name each literally.",
        $line,
    ));
})->with([
    'a location from a call' => fn(): array => ["return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: [getenv('TESTS')])]);", 7],
    'a location from a variable' => fn(): array => ["\$tests = 'tests';\n\nreturn new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: \$tests)]);", 9],
    'a location spread in' => fn(): array => ["return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: [...TESTS])]);", 7],
    'a location under a key' => fn(): array => ["return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: ['unit' => 'tests'])]);", 7],
    'a finder that leaves out what it builds' => fn(): array => ["return new ApplicationConfig(suites: [new SuiteConfig(name: 'unit', location: new FinderConfig(['tests'], [getenv('SKIP')]))]);", 7],
    'suites the application builds' => fn(): array => ["return new ApplicationConfig(\n    suites: suites(),\n);", 7],
]);

it('cannot read the tests of a config that is no PHP it can parse', function (): void {
    $suite = TestoSuite::declaredIn(Contents::of("<?php\n\nreturn new;\n"), Path::of('testo.php'));

    expect($suite instanceof CannotJudge ? $suite->why() : $suite)
        ->toStartWith('testo.php is no PHP the gate can parse, so the tests it declares cannot be read: ');
});
