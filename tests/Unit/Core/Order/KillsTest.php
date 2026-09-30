<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('counts a test\'s kills, from its first, one more at a time', function (): void {
    $first = Kills::first(TestId::of('MoneyTest::adds'));

    expect($first->count())->toBe(1)
        ->and($first->andOneMore()->andOneMore()->count())->toBe(3)
        ->and($first->andOneMore()->test())->toEqual(TestId::of('MoneyTest::adds'))
        ->and(Kills::of(TestId::of('CartTest::totals'), 4)->count())->toBe(4);
});
