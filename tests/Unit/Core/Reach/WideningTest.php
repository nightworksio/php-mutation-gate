<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Widening;

it('says why a change to a test file reaches every unit its tests run', function (Widening $widening, string $why): void {
    expect($widening->reason('tests/MoneyTest.php', 12))->toEqual(Reason::that(sprintf('`tests/MoneyTest.php` %s, so the 12 files all its tests run are reached.', $why)));
})->with([
    'new' => [fn(): Widening => Widening::New, 'is new'],
    'renamed' => [fn(): Widening => Widening::Renamed, 'was renamed'],
    'unread' => [fn(): Widening => Widening::Unread, 'cannot be read as it was and as it is'],
    'shared' => [fn(): Widening => Widening::Shared, 'changed code its tests share'],
    'dependent' => [fn(): Widening => Widening::Dependent, 'changed a test that its other tests may depend on'],
]);
