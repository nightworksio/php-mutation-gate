<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestCases;

it('holds a file it names, or one with the suffix of its directory, and none it leaves out', function (string $file, bool $held): void {
    $cases = TestCases::of(
        Paths::of(Path::of('checks/smoke.php')),
        Paths::of(Path::of('tests/Fixture'), Path::of('checks/smoke.php')),
        SuiteDirectory::of(Path::of('tests'), 'Spec.php'),
    );

    expect($cases->holds(Path::of($file)))->toBe($held);
})->with([
    'with the suffix' => ['tests/MoneySpec.php', true],
    'without it' => ['tests/Support/Money.php', false],
    'outside the directory' => ['src/MoneySpec.php', false],
    'left out' => ['tests/Fixture/MoneySpec.php', false],
    'named, but left out' => ['checks/smoke.php', false],
]);

it('holds a file it names outside every directory', function (): void {
    expect(TestCases::of(Paths::of(Path::of('checks/smoke.php')), Paths::none())->holds(Path::of('checks/smoke.php')))->toBeTrue();
});

it('picks those of some files it holds, in their order, and knows none where nothing says how the suite tells them', function (): void {
    $files = Paths::of(Path::of('tests/BSpec.php'), Path::of('tests/Help.php'), Path::of('tests/ASpec.php'));

    expect(TestCases::of(Paths::none(), Paths::none(), SuiteDirectory::of(Path::of('tests'), 'Spec.php'))->among($files))
        ->toEqual(Paths::of(Path::of('tests/BSpec.php'), Path::of('tests/ASpec.php')))
        ->and(TestCases::unknown()->among($files))->toEqual(Paths::none());
});
