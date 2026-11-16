<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageDrivers;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\Test\Group;

/** A patched run whose pcov and Xdebug steps are on or off. */
function driversPatching(bool $pcovOff, bool $xdebugOff): Patching
{
    $toggles = Toggles::of(Toggle::PestPcovOff, $pcovOff)->with(Toggle::PestXdebugOff, $xdebugOff);

    return Patching::on(Group::named('mutation-canary'))
        ->stepping(PestSteps::of($toggles, NarrowedKillTrust::Vouch, LongFilter::Files, Duplicates::JudgeOnce));
}

it('turns pcov off under pest.pcovOff, and on wherever the streams record with it, whatever the run was started with', function (bool $pcovOff, bool $xdebugOff, bool $withPcov, array $set): void {
    expect(CoverageDrivers::of(driversPatching($pcovOff, $xdebugOff), $withPcov))->toBe($set);
})->with([
    'both off' => [true, true, false, ['PCOV_ENABLED' => '0', 'XDEBUG_MODE' => 'off']],
    'pcov recording' => [true, true, true, ['PCOV_ENABLED' => '1', 'XDEBUG_MODE' => 'off']],
    'pcov recording, nothing off' => [false, false, true, ['PCOV_ENABLED' => '1']],
    'nothing off' => [false, false, false, []],
    'Xdebug left' => [true, false, false, ['PCOV_ENABLED' => '0']],
]);
