<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestName;

it('names a test by its file and description', function (): void {
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it adds');

    expect($test->file())->toEqual(Path::of('tests/Unit/MoneyTest.php'))
        ->and($test->description())->toBe('it adds')
        ->and($test->value())->toBe('tests/Unit/MoneyTest.php::it adds');
});
