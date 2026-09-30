<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The ledgers of a pull request whose own ledger records this commit as passed, or none. */
function modeLedgers(string $passed): Ledgers
{
    $store = new ProofStoreFake();
    $ledger = $passed === ''
        ? Ledger::empty()
        : Ledger::empty()->withPassed(Passed::of(Revision::ref($passed), 'check', 0));
    $store->write(Scope::pullRequest(7), $ledger);
    $ci = new CiPlanFake(ShardId::of(1), RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $standing = Standing::of($ci, RepositoryFake::onMain(Revision::ref('head')), Absent::setting());

    return $standing instanceof Standing
        ? Ledgers::read($store, $standing, Writing::Auto)
        : throw new RuntimeException($standing->why());
}

it('considers every unit in a full run', function (): void {
    expect(Mode::full()->base(modeLedgers('p1')))->toEqual(CannotTell::because('A full run considers every unit.'));
});

it('reads the change since the ref it names', function (): void {
    expect(Mode::since('v1.2')->base(modeLedgers('p1')))->toEqual(Revision::ref('v1.2'));
});

it('reads the change since the newest commit of its own scope that passed', function (): void {
    expect(Mode::since(Mode::LAST_PASSED)->base(modeLedgers('p1')))->toEqual(Revision::ref('p1'));
});

it('is full where no commit of its scope has passed yet', function (): void {
    expect(Mode::since('last-passed')->base(modeLedgers('')))->toEqual(CannotTell::because(
        'No commit of this scope has passed yet, so the run considers every unit.',
    ));
});
