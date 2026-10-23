<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

it('keeps its tests in the framework\'s conventional directory where nothing names another', function (): void {
    $specs = SuiteDirectory::of(Path::of('spec'), 'Spec.php');
    $conventional = TestSuite::conventional($specs);
    $none = TestSuite::of([], $specs, Paths::none(), Paths::none(), DeclaredSuites::none());

    expect([$conventional->directories(), $none->directories()])->toEqual([[$specs], [$specs]])
        ->and([$conventional->conventionalDirectory(), $none->conventionalDirectory()])->toEqual([$specs, $specs])
        ->and($conventional->suites())->toEqual(DeclaredSuites::none());
});

it('holds the tests of the directories and files it names, less those it leaves out, in its suites', function (): void {
    $unit = SuiteDirectory::of(Path::of('tests/Unit'), '');
    $suites = DeclaredSuites::of(DeclaredSuite::named('Unit', Paths::none(), Paths::none(), $unit));
    $suite = TestSuite::of(
        [$unit],
        SuiteDirectory::conventional(),
        Paths::of(Path::of('checks/SmokeCheck.php')),
        Paths::of(Path::of('tests/Unit/fixture')),
        $suites,
    );

    expect($suite->paths())->toEqual(Paths::of(Path::of('tests/Unit')))
        ->and($suite->conventionalDirectory())->toEqual(SuiteDirectory::conventional())
        ->and($suite->suites())->toBe($suites)
        ->and($suite->holds(Path::of('tests/Unit/MoneyTest.php')))->toBeTrue()
        ->and($suite->holdsTestCase(Path::of('checks/SmokeCheck.php')))->toBeTrue()
        ->and($suite->holds(Path::of('tests/Unit/fixture/Money.php')))->toBeFalse();
});
