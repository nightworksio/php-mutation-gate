<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Stub\Stub;
use NightWorksIO\MutationGate\Core\Stub\TestFile;

$test = "it('kills mutant 49e02fb39669', function (): void {\n});";

it('adds a test to the file it follows, and prints it under the file it goes in', function () use ($test): void {
    $stub = Stub::into(TestFile::read(Path::of('tests/MoneyTest.php'), Contents::of("<?php\n")), $test, 'mutant 49e02fb39669');

    expect($stub->file())->toEqual(Path::of('tests/MoneyTest.php'))
        ->and($stub->isNew())->toBeFalse()
        ->and($stub->contents())->toBe(sprintf("<?php\n\n%s\n", $test))
        ->and($stub->printed())->toBe(sprintf("// Add to tests/MoneyTest.php:\n\n%s", $test))
        ->and($stub->written())->toBe('Added a test for mutant 49e02fb39669 to tests/MoneyTest.php.');
});

it('writes a new file of its own, and prints the whole of it', function () use ($test): void {
    $stub = Stub::created(Path::of('tests/Unit/MoneyTest.php'), $test, AssertionStyle::Pest, 'cluster c1de8298b6e2');

    expect($stub->isNew())->toBeTrue()
        ->and($stub->contents())->toBe(sprintf("<?php\n\ndeclare(strict_types=1);\n\n%s\n", $test))
        ->and($stub->printed())->toBe(sprintf("// A new file, tests/Unit/MoneyTest.php:\n\n<?php\n\ndeclare(strict_types=1);\n\n%s", $test))
        ->and($stub->written())->toBe('Wrote tests/Unit/MoneyTest.php, with a test for cluster c1de8298b6e2.');
});
