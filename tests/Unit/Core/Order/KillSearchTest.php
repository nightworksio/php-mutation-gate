<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;

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

it('holds no mutant back unless told which subsumes which, and then holds by that', function (): void {
    $held = Subsumption::none()->with(
        Path::of('src/Money.php'),
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b\n", 0),
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+c\n", 0),
    );
    $search = KillSearch::of(Ordering::runner(), MatrixKind::Full);

    expect(KillSearch::standard()->held())->toEqual(NotGiven::value())
        ->and($search->held())->toEqual(NotGiven::value())
        ->and($search->holdingBack($held)->held())->toBe($held)
        ->and($search->holdingBack($held)->matrix())->toBe(MatrixKind::Full)
        ->and($search->holdingBack($held)->ordering())->toBe($search->ordering());
});
