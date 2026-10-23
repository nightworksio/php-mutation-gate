<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\CodeceptionSuite;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The suite a Codeception config declares in a scratch project holding these files. */
function codeceptionSuite(string $config, string ...$files): TestSuite|CannotJudge
{
    $root = Scratch::directory();
    $counter = count($files);

    for ($each = 0; $each < $counter; $each += 2) {
        Scratch::write($root, $files[$each], $files[$each + 1]);
    }

    return CodeceptionSuite::declaredIn(Contents::of($config), Path::of('codeception.yml'), Root::of($root));
}

it('keeps each suite\'s tests in its directory under the tests directory, at its path or its name, in files ending in Test.php, Cest.php or Cept.php', function (): void {
    $suite = codeceptionSuite(
        "paths:\n  tests: checks\nsuites:\n  Inline:\n    path: inlined\n",
        'checks/Unit.suite.yml',
        "actor: UnitTester\n",
        'checks/Functional.suite.dist.yml',
        "path: functional\n",
        'checks/Functional.suite.yml',
        "path: Functional\nmodules:\n  enabled: [Asserts]\n",
    );

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)
        ->toEqual(Paths::of(Path::of('checks/Functional'), Path::of('checks/inlined'), Path::of('checks/Unit')))
        ->and($suite instanceof TestSuite ? $suite->suites() : $suite)->toEqual(DeclaredSuites::none())
        ->and($suite instanceof TestSuite ? $suite->conventionalDirectory() : $suite)->toEqual(SuiteDirectory::of(Path::of('checks'), 'Test.php'))
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/Unit/MoneyTest.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/Functional/MoneyCest.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/inlined/BuyCept.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('checks/Unit/Money.php')))->toBeFalse();
});

it('leaves out a suite that serves the application from another process, and every suite where the config does', function (): void {
    $suite = codeceptionSuite('{}', 'tests/Unit.suite.yml', '{}', 'tests/Acceptance.suite.yml', "modules:\n  enabled:\n    - PhpBrowser:\n        url: http://localhost\n");
    $everywhere = codeceptionSuite("modules:\n  enabled: [WebDriver]\n", 'tests/Unit.suite.yml', '{}');

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)->toEqual(Paths::of(Path::of('tests/Unit')))
        ->and($suite instanceof TestSuite ? $suite->elsewhere() : $suite)->toBe(['Acceptance'])
        ->and($everywhere instanceof TestSuite ? $everywhere->paths() : $everywhere)->toEqual(Paths::of(Path::of('tests')))
        ->and($everywhere instanceof TestSuite ? $everywhere->elsewhere() : $everywhere)->toBe(['Unit'])
        ->and(($unit = codeceptionSuite('{}', 'tests/Unit.suite.yml', '{}')) instanceof TestSuite ? $unit->elsewhere() : $unit)->toBe([]);
});

it('keeps the tests in tests where the config declares no suite, or there is no config', function (): void {
    $suite = codeceptionSuite('namespace: Tests');

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)->toEqual(Paths::of(Path::of('tests')))
        ->and(CodeceptionSuite::conventional()->conventionalDirectory())->toEqual(SuiteDirectory::of(Path::of('tests'), 'Test.php'))
        ->and(CodeceptionSuite::conventional()->directories())->toEqual([
            SuiteDirectory::of(Path::of('tests'), 'Test.php'),
            SuiteDirectory::of(Path::of('tests'), 'Cest.php'),
            SuiteDirectory::of(Path::of('tests'), 'Cept.php'),
        ]);
});

it('cannot read a config, or a suite\'s own config, that is not YAML, or that it cannot read', function (): void {
    $config = codeceptionSuite("paths: [unclosed\n");
    $suite = codeceptionSuite('{}', 'tests/Unit.suite.yml', "modules: [unclosed\n");
    $root = Scratch::directory();
    mkdir(sprintf('%s/tests/Unit.suite.yml', $root), recursive: true);
    $unread = CodeceptionSuite::declaredIn(Contents::of('{}'), Path::of('codeception.yml'), Root::of($root));

    expect($config instanceof CannotJudge ? $config->why() : $config)->toStartWith('codeception.yml is not YAML, so the tests it declares cannot be read: ')
        ->and($suite instanceof CannotJudge ? $suite->why() : $suite)->toStartWith('Unit.suite.yml is not YAML, so the tests it declares cannot be read: ')
        ->and($unread)->toEqual(CannotJudge::because(sprintf('The Codeception suite config %s/tests/Unit.suite.yml could not be read.', $root)));
});
