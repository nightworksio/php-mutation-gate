<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\LocalWork;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The change the plans are of: a line of src/Held.php. */
$makeChange = static fn(): Changes => Changes::of(Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(3))));

/** The units the one shard of a plan of the change runs, on an empty ledger. */
$planned = static function (QuickRound|NotGiven $round, Setting ...$settings) use ($makeChange): array {
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $makeChange(), [Revision::workingTree()->name() => Flows::FILES, Flows::MAIN => Flows::FILES]);
    $plan = Planned::from(new Planning(Flows::adapters(Flows::project(), [], $checkout), Flows::settings(...$settings), Flows::setup())->plan(
        Mode::since(Flows::MAIN),
        CoverageRun::of(WholeSuite::tests(), Workspace::coverage()),
        Cut::exactly(1),
        MatrixKind::FirstKiller,
        quick: $round,
    ));

    return $plan instanceof Plan ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...[...$plan][0]->units()]) : [$plan->why()];
};

it('runs only the units a quick round\'s change reaches, and every unit with no proof where it is not held to its change or is no quick round', function () use ($planned, $makeChange): void {
    $round = QuickRound::after($makeChange());

    expect($planned($round))->toBe(['src/Held.php'])
        ->and($planned($round, LocalWork::changeOnly(on: false)))->toBe(['src/Held.php', 'src/Money.php'])
        ->and($planned(NotGiven::value()))->toBe(['src/Held.php', 'src/Money.php']);
});
