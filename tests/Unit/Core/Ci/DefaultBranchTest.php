<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Proof\Scope;

it('takes the branch the config names before any that is detected', function (): void {
    expect(DefaultBranch::of('trunk', Scope::branch('develop')))->toEqual(Scope::branch('trunk'));
});

it('takes the first branch detected where the config names none', function (): void {
    $unnamed = CannotTell::because('The CI does not name the default branch.');

    expect(DefaultBranch::of(new Absent(), $unnamed, Scope::branch('develop'), Scope::branch('trunk')))
        ->toEqual(Scope::branch('develop'));
});

it('takes main where nothing names a branch, or the config names one that is not', function (
    string|Absent $configured,
): void {
    expect(DefaultBranch::of($configured, CannotTell::because('No origin/HEAD.')))->toEqual(Scope::branch('main'));
})->with(['nothing named' => [fn(): Absent => new Absent()], 'no branch named' => ['a..b']]);
