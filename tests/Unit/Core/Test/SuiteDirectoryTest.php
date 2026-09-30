<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

it('holds every file inside it, and tells its test cases by their suffix', function (): void {
    $directory = SuiteDirectory::of(Path::of('tests/Feature'), '.phpt');

    expect($directory->holds(Path::of('tests/Feature/Fakes/Clock.php')))->toBeTrue()
        ->and($directory->holds(Path::of('tests/Unit/money.phpt')))->toBeFalse()
        ->and($directory->holdsTestCase(Path::of('tests/Feature/money.phpt')))->toBeTrue()
        ->and($directory->holdsTestCase(Path::of('tests/Feature/MoneyTest.php')))->toBeFalse()
        ->and($directory->holdsTestCase(Path::of('tests/Unit/money.phpt')))->toBeFalse();
});

it('tells test cases by PHPUnit\'s suffix where it names none, under tests where nothing says otherwise', function (): void {
    expect(SuiteDirectory::of(Path::of('spec'), ''))->toEqual(SuiteDirectory::of(Path::of('spec'), 'Test.php'))
        ->and(SuiteDirectory::conventional())->toEqual(SuiteDirectory::of(Path::of('tests'), 'Test.php'))
        ->and(SuiteDirectory::conventional()->holdsTestCase(Path::of('tests/Unit/MoneyTest.php')))->toBeTrue()
        ->and(SuiteDirectory::conventional()->holdsTestCase(Path::of('tests/Unit/Money.php')))->toBeFalse();
});
