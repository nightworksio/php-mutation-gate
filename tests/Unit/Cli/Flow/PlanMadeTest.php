<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CoverageMeasured;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

it('is briefed as planned without a fresh map, saying how its map was read, keeping what it learned and what it was made without', function (): void {
    $unread = Warnings::of(Warning::that('The own ledger cannot be read.'));
    $made = PlanMade::of(Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::none()), 'Coverage: measured against the kept map.')
        ->without($unread)
        ->learning('timeouts.multiple is 3, learned from 40 runs.');
    $early = $made->plannedEarly(CoverageMeasured::of(CoverageRead::from(Path::of('.mutation-gate/coverage')), 'Coverage: the kept map, as it is.'));

    expect($made->plan()->briefing()->isEarly())->toBeFalse()
        ->and($early->plan()->briefing()->isEarly())->toBeTrue()
        ->and($early->coverage())->toBe('Coverage: the kept map, as it is.')
        ->and($early->unread())->toBe($unread)
        ->and($early->learned())->toBe(['timeouts.multiple is 3, learned from 40 runs.'])
        ->and($early->plan()->commit())->toEqual(Revision::ref('5eeca8f'));
});
