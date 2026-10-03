<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Runner\Parallelism;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

it('lists holds as groups, raises limits, has no group in every key and opens no shard of its own, by default', function (): void {
    $standard = RunnerBehaviour::standard();

    expect([$standard->holdsAsLoaded(), $standard->raisesLimits(), $standard->opensEachShard()])
        ->toBe([false, true, false])
        ->and($standard->readByEveryKey())->toEqual(Groups::none())
        ->and($standard->whyNotFull())->toBe(NotFull::FirstKillers)
        ->and($standard->parallelism())->toBe(Parallelism::Serial)
        ->and($standard->testStyle())->toBe(AssertionStyle::PhpUnit);
});

it('writes its tests in the style it is told', function (): void {
    expect(RunnerBehaviour::standard()->writingTestsIn(AssertionStyle::Pest)->testStyle())->toBe(AssertionStyle::Pest);
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
        ->and(RunnerBehaviour::standard()->raisingNoLimit()->holdsAsLoaded())->toBeFalse()
        ->and(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->whyNotFull())
        ->toBe(NotFull::Infection)
        ->and(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->raisesLimits())->toBeTrue()
        ->and(RunnerBehaviour::standard()->runningPerCore()->parallelism())->toBe(Parallelism::PerCore)
        ->and(RunnerBehaviour::standard()->runningPerCore()->raisesLimits())->toBeTrue();
});
