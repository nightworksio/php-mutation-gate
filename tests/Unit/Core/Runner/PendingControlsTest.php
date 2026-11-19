<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;

it('hands over what the controls found already, and stops nothing', function (): void {
    $runs = ControlRuns::none();
    $pending = PendingControls::ran($runs);
    $pending->stop();

    expect($pending->taken())->toBe($runs)
        ->and(PendingControls::ran(CannotJudge::because('No control runs.'))->taken())->toEqual(CannotJudge::because('No control runs.'));
});

it('waits for the controls only once taken, and stops them once told to', function (): void {
    $calls = [];
    $runs = ControlRuns::none();
    $pending = PendingControls::taking(
        static function () use (&$calls, $runs): ControlRuns {
            $calls[] = 'taken';

            return $runs;
        },
        static function () use (&$calls): void {
            $calls[] = 'stopped';
        },
    );
    $before = $calls;
    $taken = $pending->taken();
    $pending->stop();

    expect($before)->toBe([])
        ->and($taken)->toBe($runs)
        ->and($calls)->toBe(['taken', 'stopped']);
});
