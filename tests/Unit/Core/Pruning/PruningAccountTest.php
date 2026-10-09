<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

it('counts the units and mutants a run carried for its pruned mutators, and the measured time they took', function (): void {
    $account = PruningCases::account();

    expect($account->isNone())->toBeFalse()
        ->and([...$account->mutators()])->toBe(['Minus', 'Plus'])
        ->and($account->units())->toBe(1)
        ->and($account->carried())->toBe(3)
        ->and($account->saved()->seconds())->toBe(120.0)
        ->and($account->window()->mutants())->toBe(2)
        ->and($account->audit()->seconds())->toBe(604_800.0);
});

it('names the last mutant each pruned mutator let through, and none for one that never did', function (): void {
    $account = PruningCases::account();

    expect($account->lastOf(RunnerMutatorName::of('Plus')))->toBe('Plus-9')
        ->and($account->lastOf(RunnerMutatorName::of('Minus')))->toBeInstanceOf(NotGiven::class);
});

it('accounts for nothing where the run pruned nothing, whatever the results carry', function (): void {
    $none = PruningAccount::of(
        Pruned::none(),
        PruningCases::clean('Plus'),
        Name::of('pest'),
        Window::of(2),
        Seconds::days(7),
        UnitResults::none(),
    );

    expect($none->isNone())->toBeTrue()
        ->and(PruningAccount::none()->isNone())->toBeTrue()
        ->and($none->carried())->toBe(0)
        ->and($none->saved()->seconds())->toBe(0.0);
});
