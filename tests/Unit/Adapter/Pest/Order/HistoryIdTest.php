<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\HistoryId;

it('keys a test as PHPUnit\'s history does, from its id as the coverage map names it', function (string $map, string $history): void {
    expect(HistoryId::of($map))->toBe($history);
})->with([
    'a Pest test' => ['P\Tests\MoneySpec::__pest_evaluable_it_adds', 'P\Tests\MoneySpec::__pest_evaluable_it_adds'],
    'a PHPUnit test' => ['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testAdds'],
    'a named row' => [
        'P\Tests\ShapesSpec::__pest_evaluable_it_is_held_in_every_row#(1)',
        'P\Tests\ShapesSpec::__pest_evaluable_it_is_held_in_every_row with data set "(1)"',
    ],
    'a numbered row' => ['Tests\MoneyTest::testAdds#0', 'Tests\MoneyTest::testAdds with data set #0'],
    'a negative numbered row' => ['Tests\MoneyTest::testAdds#-3', 'Tests\MoneyTest::testAdds with data set #-3'],
    'a row named with a number and more' => ['Tests\MoneyTest::testAdds#0a', 'Tests\MoneyTest::testAdds with data set "0a"'],
    'a row whose name holds a #' => ['Tests\MoneyTest::testAdds#one #1', 'Tests\MoneyTest::testAdds with data set "one #1"'],
    'a row named nothing' => ['Tests\MoneyTest::testAdds#', 'Tests\MoneyTest::testAdds with data set ""'],
    'a # before any ::' => ['Tests#Money::testAdds', 'Tests#Money::testAdds'],
]);
