<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Remeasuring;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * What a kept map measures again after these changes, in a project of one tree whose files on disk are these.
 *
 * @param array<string, string> $files what each file holds, by its path
 */
function remeasuredAfter(Changes $changes, array $files = []): TestPaths|WholeSuite
{
    $sources = Sources::none();

    foreach ($files as $path => $text) {
        $sources = $sources->withNow(Path::of($path), Contents::of($text));
    }

    $layout = Layout::standard(Paths::of(Path::of('tests/Pest.php')))->runBy(Glob::of('.github/workflows/gate.yml'));

    return new Remeasuring(
        $layout,
        Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))),
    )->of($changes, $sources);
}

$modified = static fn(string $path): Change => Change::modified(Path::of($path), Lines::none());

$clock = "<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n";

$ticking = "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n";

it('measures again each changed, new, gone or renamed test file', function (): void {
    expect(remeasuredAfter(Changes::of(
        Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::none()),
        Change::added(Path::of('tests/Unit/NewTest.php'), Lines::none()),
        Change::deleted(Path::of('tests/Unit/GoneTest.php')),
        Change::renamed(Path::of('tests/Unit/OldTest.php'), Path::of('tests/Unit/MovedTest.php'), Lines::none()),
    )))->toEqual(TestPaths::of(Paths::of(
        Path::of('tests/Unit/MoneyTest.php'),
        Path::of('tests/Unit/NewTest.php'),
        Path::of('tests/Unit/GoneTest.php'),
        Path::of('tests/Unit/MovedTest.php'),
        Path::of('tests/Unit/OldTest.php'),
    )));
});

it('measures again the tests that use changed support', function () use ($modified, $clock, $ticking): void {
    expect(remeasuredAfter(Changes::of($modified('tests/Fakes/ClockFake.php')), [
        'tests/Fakes/ClockFake.php' => $clock,
        'tests/Unit/ClockTest.php' => $ticking,
        'tests/Unit/MoneyTest.php' => "<?php\n\nit('adds', fn () => 1);\n",
    ]))->toEqual(TestPaths::of(Paths::of(Path::of('tests/Unit/ClockTest.php'))));
});

it('measures nothing again after a change to a source or to anything else', function () use ($modified): void {
    expect(remeasuredAfter(Changes::of($modified('src/Money.php'), $modified('README.md'))))
        ->toEqual(TestPaths::of(Paths::none()));
});

it('measures the whole suite after a change that reaches everything', function (Change $change, string $disk): void {
    $files = $disk === '' ? [] : [$change->path()->value() => $disk];

    expect(remeasuredAfter(Changes::of(
        Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::none()),
        $change,
    ), $files))->toEqual(WholeSuite::tests());
})->with([
    'a file that defines the runner' => [Change::modified(Path::of('tests/Pest.php'), Lines::none()), ''],
    'the manifest' => [Change::modified(Path::of('composer.json'), Lines::none()), ''],
    'one renamed from a deciding name' => [
        Change::renamed(Path::of('phpunit.xml'), Path::of('tests/old.xml'), Lines::none()),
        '',
    ],
    'the CI definition that runs the gate' => [Change::modified(Path::of('.github/workflows/gate.yml'), Lines::none()), ''],
    'support that runs code when loaded' => [
        Change::modified(Path::of('tests/Support/boot.php'), Lines::none()),
        "<?php\n\nputenv('APP_ENV=testing');\n",
    ],
    'support none of whose versions can be read' => [Change::deleted(Path::of('tests/Fakes/ClockFake.php')), ''],
]);
