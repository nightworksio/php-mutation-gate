<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

/** The suite a PHPUnit config that says this declares. */
$suiteOf = static fn(string $config): PhpUnitSuite|CannotJudge => PhpUnitSuite::declaredIn(
    Contents::of($config),
    Path::of('phpunit.xml'),
);

it('holds the files in every test suite\'s directories and those it names, less what it excludes', function () use (
    $suiteOf,
): void {
    $suite = $suiteOf(<<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit">
                    <directory>tests/Unit</directory>
                    <exclude>tests/Unit/fixture</exclude>
                </testsuite>
                <testsuite name="Feature">
                    <directory> ./tests/Feature/ </directory>
                    <file>tests/SmokeTest.php</file>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);
    $holds = static fn(string $file): bool => $suite instanceof PhpUnitSuite && $suite->holds(Path::of($file));

    expect($suite instanceof PhpUnitSuite ? $suite->directories() : $suite)->toEqual([
        SuiteDirectory::of(Path::of('tests/Unit'), ''),
        SuiteDirectory::of(Path::of('tests/Feature'), ''),
    ])
        ->and($holds('tests/Unit/MoneyTest.php'))->toBeTrue()
        ->and($holds('tests/Feature/Http/KernelTest.php'))->toBeTrue()
        ->and($holds('tests/SmokeTest.php'))->toBeTrue()
        ->and($holds('tests/Unit/fixture/tests/HeldSpec.php'))->toBeFalse()
        ->and($holds('tests/Support/Helper.php'))->toBeFalse()
        ->and($holds('src/Money.php'))->toBeFalse();
});

it('reads each test suite by its name, with its own directories, files and excludes', function () use ($suiteOf): void {
    $suite = $suiteOf(<<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit">
                    <directory suffix="Spec.php">tests/Unit</directory>
                    <exclude>tests/Unit/fixture</exclude>
                </testsuite>
                <testsuite name="Feature">
                    <directory>tests/Feature</directory>
                    <file>tests/SmokeTest.php</file>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    expect($suite instanceof PhpUnitSuite ? $suite->suites() : $suite)->toEqual(DeclaredSuites::of(
        DeclaredSuite::named('Unit', Paths::none(), Paths::of(Path::of('tests/Unit/fixture')), SuiteDirectory::of(Path::of('tests/Unit'), 'Spec.php')),
        DeclaredSuite::named('Feature', Paths::of(Path::of('tests/SmokeTest.php')), Paths::none(), SuiteDirectory::of(Path::of('tests/Feature'), '')),
    ))
        ->and(PhpUnitSuite::conventional()->suites())->toEqual(DeclaredSuites::none());
});

it('holds the conventional directory where the config names no test directory', function (
    PhpUnitSuite|CannotJudge $suite,
): void {
    expect($suite instanceof PhpUnitSuite ? $suite->directories() : $suite)->toEqual([SuiteDirectory::conventional()])
        ->and($suite instanceof PhpUnitSuite && $suite->holds(Path::of('tests/MoneyTest.php')))->toBeTrue();
})->with([
    'a config with no suite' => [fn(): PhpUnitSuite|CannotJudge => $suiteOf('<phpunit/>')],
    'the convention itself' => [PhpUnitSuite::conventional()],
]);

it('tells each directory\'s files of test cases by its suffix, PHPUnit\'s where it names none', function () use (
    $suiteOf,
): void {
    $suite = $suiteOf(<<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Feature">
                    <directory suffix=".php"> ./tests/Feature/ </directory>
                    <file>tests/Smoke.php</file>
                    <exclude>tests/Feature/fixture</exclude>
                </testsuite>
                <testsuite name="Again"><directory suffix="">tests/Unit</directory></testsuite>
                <testsuite name="Phpt"><directory suffix=".phpt">tests/Unit</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    $cases = static fn(string $file): bool => $suite instanceof PhpUnitSuite && $suite->holdsTestCase(Path::of($file));

    expect($suite instanceof PhpUnitSuite ? $suite->directories() : $suite)->toEqual([
        SuiteDirectory::of(Path::of('tests/Unit'), 'Test.php'),
        SuiteDirectory::of(Path::of('tests/Feature'), '.php'),
        SuiteDirectory::of(Path::of('tests/Unit'), 'Test.php'),
        SuiteDirectory::of(Path::of('tests/Unit'), '.phpt'),
    ])
        ->and($cases('tests/Unit/MoneyTest.php'))->toBeTrue()
        ->and($cases('tests/Unit/money.phpt'))->toBeTrue()
        ->and($cases('tests/Unit/Support/Money.php'))->toBeFalse()
        ->and($cases('tests/Feature/Checkout.php'))->toBeTrue()
        ->and($cases('tests/Feature/fixture/Checkout.php'))->toBeFalse()
        ->and($cases('tests/Smoke.php'))->toBeTrue()
        ->and($cases('src/MoneyTest.php'))->toBeFalse();
});

it('cannot read a suite from a config that is not XML', function () use ($suiteOf): void {
    expect($suiteOf('<phpunit><testsuites>'))
        ->toEqual(CannotJudge::because('phpunit.xml is not XML, so the test suite it declares cannot be read.'));
});
