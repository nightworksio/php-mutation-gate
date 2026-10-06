<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Reproduced;
use NightWorksIO\MutationGate\Cli\Flow\Reproducing;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Sought;

afterEach(function (): void {
    Scratch::sweep();
});

/** A ledger holding a proof of each file of the fixture, with the mutants the fake runner finds in it, established at this instant. */
$ledger = static function (string $at, string ...$files): Ledger {
    $ledger = Ledger::empty();

    foreach ($files as $file) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::sha256Of(sprintf('%s %s', $file, $at)),
            Path::of($file),
            Flows::mutantsOf($file),
            Run::of(sprintf('github:%s', $at), Moment::at($at), Digest::sha256Of('base')),
        ));
    }

    return $ledger;
};

/** The default branch's ledger holding the fixture's two files. */
$store = static function () use ($ledger): ProofStoreFake {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger('2026-09-29T10:00:00Z', 'src/Money.php', 'src/Held.php'));

    return $store;
};

it('runs the newest record of a mutant again by the whole suite, allowed the configured cap, withholding what the CI withholds', function () use ($store): void {
    $runner = ScriptedRunner::fixture();

    $reproduced = new Reproducing(Flows::adapters(Flows::project(), [], $store(), $runner), Flows::settings())
        ->reproduce(Sought::of('49e02f'));
    $asked = $runner->reproductions();

    expect($reproduced instanceof Reproduced ? [
        $reproduced->recorded->mutant()->id()->value(),
        $reproduced->recorded->proof()->run()->id(),
        $reproduced->now->mutant() instanceof Mutant ? $reproduced->now->mutant()->status() : null,
        $reproduced->now->printed(),
    ] : $reproduced)->toBe(['49e02fb39669', 'github:2026-09-29T10:00:00Z', MutantStatus::Survived, 'scripted: 49e02fb39669 run again'])
        ->and($reproduced instanceof Reproduced ? $reproduced->judgedBy : $reproduced)->toEqual(WholeSuite::tests())
        ->and(count($asked))->toBe(1)
        ->and([$asked[0][2]->seconds(), $asked[0][1]->withheld(), $asked[0][1]->memory()])->toEqual([
            Flows::settings()->triage()->most()->seconds(),
            Withheld::standard()->and(CiPlanFake::withheld()),
            MemoryCap::standard(),
        ])
        ->and([...$asked[0][1]->files()])->toEqual([$reproduced instanceof Reproduced ? $reproduced->recorded->mutant()->location()->file() : null]);
});

it('reproduces a mutant under the memory cap the config sets, as a run has it', function () use ($store): void {
    $runner = ScriptedRunner::fixture();
    $settings = Flows::settings(ConfiguredRunner::uses('fake')->cappedAt(MemoryCap::of(512, MemoryUnit::Megabytes)));

    new Reproducing(Flows::adapters(Flows::project(), [], $store(), $runner), $settings)->reproduce(Sought::of('49e02f'));

    expect(array_map(static fn(array $asked): MemoryCap => $asked[1]->memory(), $runner->reproductions()))
        ->toEqual([MemoryCap::of(512, MemoryUnit::Megabytes)]);
});

it('runs a held unit\'s mutant by the group that holds it', function () use ($store): void {
    $reproduced = new Reproducing(Flows::adapters(Flows::project(), [], $store(), ScriptedRunner::fixture()), Flows::settings())
        ->reproduce(Sought::of('8705b7dc7d27'));

    expect($reproduced instanceof Reproduced ? $reproduced->judgedBy : $reproduced)->toEqual(Group::named('holds:src/Held.php'));
});

it('takes the run\'s own scope\'s newer record of a mutant before the default branch\'s', function () use ($store, $ledger): void {
    $proofs = $store();
    $proofs->write(Scope::branch('feature'), $ledger('2026-09-30T10:00:00Z', 'src/Money.php'));
    $ci = new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main')));

    $reproduced = new Reproducing(Flows::adapters(Flows::project(), [], $proofs, $ci, ScriptedRunner::fixture()), Flows::settings())
        ->reproduce(Sought::of('49e02fb39669'));

    expect($reproduced instanceof Reproduced ? $reproduced->recorded->proof()->run()->id() : $reproduced)->toBe('github:2026-09-30T10:00:00Z');
});

it('has no record of a mutant no ledger holds, and runs nothing', function () use ($store): void {
    $runner = ScriptedRunner::fixture();

    $reproduced = new Reproducing(Flows::adapters(Flows::project(), [], $store(), $runner), Flows::settings())
        ->reproduce(Sought::of('abcdef'));

    expect($reproduced)->toBeInstanceOf(NoRecord::class)
        ->and($runner->reproductions())->toBe([]);
});

it('cannot judge where the units cannot be found, or the runner cannot run the mutant again', function () use ($store): void {
    $unread = new Reproducing(
        Flows::adapters(Flows::project(), [], $store(), new TreeSourceFake(CannotJudge::because('No tree is declared.'))),
        Flows::settings(),
    );
    $refused = new Reproducing(
        Flows::adapters(Flows::project(), [], $store(), ScriptedRunner::fixture()->refusingAgain('Pest is not installed.')),
        Flows::settings(),
    );

    expect($unread->reproduce(Sought::of('49e02f')))->toEqual(CannotJudge::because('No tree is declared.'))
        ->and($refused->reproduce(Sought::of('49e02f')))->toEqual(CannotJudge::because('Pest is not installed.'));
});
