<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

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

    expect($suite instanceof PhpUnitSuite ? $suite->directories() : $suite)
        ->toEqual(Paths::of(Path::of('tests/Unit'), Path::of('tests/Feature')))
        ->and($holds('tests/Unit/MoneyTest.php'))->toBeTrue()
        ->and($holds('tests/Feature/Http/KernelTest.php'))->toBeTrue()
        ->and($holds('tests/SmokeTest.php'))->toBeTrue()
        ->and($holds('tests/Unit/fixture/tests/HeldSpec.php'))->toBeFalse()
        ->and($holds('tests/Support/Helper.php'))->toBeFalse()
        ->and($holds('src/Money.php'))->toBeFalse();
});

it('holds the conventional directory where the config names no test directory', function (
    PhpUnitSuite|CannotJudge $suite,
): void {
    expect($suite instanceof PhpUnitSuite ? $suite->directories() : $suite)->toEqual(Paths::of(Path::of('tests')))
        ->and($suite instanceof PhpUnitSuite && $suite->holds(Path::of('tests/MoneyTest.php')))->toBeTrue();
})->with([
    'a config with no suite' => [fn(): PhpUnitSuite|CannotJudge => $suiteOf('<phpunit/>')],
    'the convention itself' => [PhpUnitSuite::conventional()],
]);

it('cannot read a suite from a config that is not XML', function () use ($suiteOf): void {
    expect($suiteOf('<phpunit><testsuites>'))
        ->toEqual(CannotJudge::because('phpunit.xml is not XML, so the test suite it declares cannot be read.'));
});
