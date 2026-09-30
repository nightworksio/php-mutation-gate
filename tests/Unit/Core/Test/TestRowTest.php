<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;

it('names a row of a data set as the runner spells it, and folds it into its test', function (): void {
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it adds');
    $row = TestRow::of($test, '"one"');

    expect($row->test())->toBe($test)
        ->and($row->row())->toBe('"one"')
        ->and($row->description())->toBe('it adds with data set "one"')
        ->and($row->value())->toBe('tests/Unit/MoneyTest.php::it adds with data set "one"');
});
