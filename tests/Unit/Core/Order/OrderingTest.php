<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('runs the runner\'s own order, or the likely killers first by a history', function (): void {
    $history = KillHistory::none()->withFunction(
        Enclosing::named(Path::of('src/Money.php'), 'add'),
        Ranking::of(Kills::of(TestId::of('a'), 1)),
    );

    expect(Ordering::runner()->putsKillersFirst())->toBeFalse()
        ->and(Ordering::runner()->history())->toEqual(KillHistory::none())
        ->and(Ordering::of(TestOrder::KillersFirst, $history)->putsKillersFirst())->toBeTrue()
        ->and(Ordering::of(TestOrder::KillersFirst, $history)->history())->toBe($history)
        ->and(Ordering::of(TestOrder::Runner, $history)->putsKillersFirst())->toBeFalse();
});
