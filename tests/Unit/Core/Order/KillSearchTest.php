<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;

it('runs each mutant\'s tests in the runner\'s own order and stops at the first killer, by default', function (): void {
    expect(KillSearch::standard()->ordering())->toEqual(Ordering::runner())
        ->and(KillSearch::standard()->matrix())->toBe(MatrixKind::FirstKiller);
});

it('holds the order it is given and how much of the kill matrix it records', function (): void {
    $ordering = Ordering::of(TestOrder::KillersFirst, KillHistory::none());
    $search = KillSearch::of($ordering, MatrixKind::Full);

    expect($search->ordering())->toBe($ordering)
        ->and($search->matrix())->toBe(MatrixKind::Full);
});
