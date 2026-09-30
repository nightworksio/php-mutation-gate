<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

it('lists holds as groups, raises limits, has no group in every key and opens no shard of its own, by default', function (): void {
    $standard = RunnerBehaviour::standard();

    expect([$standard->holdsAsLoaded(), $standard->raisesLimits(), $standard->opensEachShard()])
        ->toBe([false, true, false])
        ->and($standard->readByEveryKey())->toEqual(Groups::none());
});

it('says each way it behaves otherwise, and nothing more', function (): void {
    $canary = Group::named('mutation-canary');
    $pest = RunnerBehaviour::standard()
        ->holdingAsLoaded()
        ->raisingNoLimit()
        ->readingInEveryKey($canary)
        ->openingEachShard();

    expect([$pest->holdsAsLoaded(), $pest->raisesLimits(), $pest->opensEachShard()])->toBe([true, false, true])
        ->and($pest->readByEveryKey())->toEqual(Groups::of($canary))
        ->and(RunnerBehaviour::standard()->raisingNoLimit()->holdsAsLoaded())->toBeFalse();
});
