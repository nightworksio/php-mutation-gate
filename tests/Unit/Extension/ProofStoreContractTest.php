<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\ProofStoreContract;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/**
 * A store that answers every scope alike: this read, this companion, and this answer to every write and keep.
 */
function storeAnsweringAlike(Ledger|Unreadable $read, Contents|Missing|CannotJudge $companion, Written|NotWritten $writes): ProofStore
{
    return new readonly class ($read, $companion, $writes) implements ProofStore {
        public function __construct(
            private Ledger|Unreadable $ledger,
            private Contents|Missing|CannotJudge $map,
            private Written|NotWritten $writes,
        ) {
        }

        public function read(Scope $scope): Ledger|Unreadable
        {
            return $this->ledger;
        }

        public function write(Scope $scope, Ledger $ledger): Written|NotWritten
        {
            return $this->writes;
        }

        public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
        {
            return $this->map;
        }

        public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
        {
            return $this->writes;
        }
    };
}

/** A ledger holding one proof of a unit the contract never writes. */
function ledgerOfAnotherUnit(): Ledger
{
    $run = Run::of('github:1/1', Moment::at('2026-09-29T20:48:17Z'), Digest::sha256Of('base'));

    return Ledger::empty()->withProof(Proof::of(Digest::sha256Of('src/Tax.php'), Path::of('src/Tax.php'), Mutants::none(), $run));
}

it('says each way a store keeps nothing it is handed', function (): void {
    $store = storeAnsweringAlike(Ledger::empty(), Missing::at(Path::of('nowhere')), NotWritten::because('The disk is full.'));

    expect([...ProofStoreContract::failures($store)])->toBe([
        'It keeps no ledger for refs/pull/12: The disk is full.',
        'It reads back no proof of src/Ledger.php for refs/pull/12.',
        'It keeps no coverage map for refs/pull/13: The disk is full.',
        'It reads back no coverage map beside refs/pull/13.',
    ]);
});

it('says each way a store reads what was written to one scope from every other', function (): void {
    $store = storeAnsweringAlike(ledgerOfAnotherUnit(), Contents::of('another map'), Written::to('everywhere'));

    expect([...ProofStoreContract::failures($store)])->toBe([
        'It reads proofs for refs/heads/main, which nothing wrote.',
        'It reads a coverage map beside refs/heads/main, where none was kept.',
        'It reads back no proof of src/Ledger.php for refs/pull/12.',
        'It reads proofs for refs/heads/main, which nothing wrote.',
        'It reads back another coverage map beside refs/pull/13 than was kept.',
        'It reads a coverage map beside refs/heads/main, where none was kept.',
        'It reads proofs for refs/pull/13, which nothing wrote.',
    ]);
});

it('says each way a store cannot read what it holds', function (): void {
    $store = storeAnsweringAlike(
        Unreadable::because(UnreadReason::Refused, 'memory', 'denied'),
        CannotJudge::because('Denied.'),
        Written::to('memory'),
    );

    expect([...ProofStoreContract::failures($store)])->toBe([
        sprintf('It reads no ledger for refs/heads/main: %s', Unreadable::because(UnreadReason::Refused, 'memory', 'denied')->why()),
        'It reads no coverage map beside refs/heads/main: Denied.',
        sprintf('It reads no ledger for refs/pull/12: %s', Unreadable::because(UnreadReason::Refused, 'memory', 'denied')->why()),
        sprintf('It reads no ledger for refs/heads/main: %s', Unreadable::because(UnreadReason::Refused, 'memory', 'denied')->why()),
        'It reads no coverage map beside refs/pull/13: Denied.',
        'It reads no coverage map beside refs/heads/main: Denied.',
        sprintf('It reads no ledger for refs/pull/13: %s', Unreadable::because(UnreadReason::Refused, 'memory', 'denied')->why()),
    ]);
});

it('says each way a store opened read-only keeps what it is handed', function (): void {
    expect([...ProofStoreContract::readOnlyFailures(new ProofStoreFake())])->toBe([
        'It says it keeps the ledger for refs/pull/12, opened read-only.',
        'It reads proofs for refs/pull/12, which nothing wrote.',
        'It says it keeps the coverage map for refs/pull/13, opened read-only.',
        'It reads a coverage map beside refs/pull/13, where none was kept.',
    ]);
});

it('says a store opened read-only that keeps nothing and gives no reason', function (): void {
    $store = storeAnsweringAlike(Ledger::empty(), Missing::at(Path::of('nowhere')), NotWritten::because(''));

    expect([...ProofStoreContract::readOnlyFailures($store)])->toBe([
        'It keeps no ledger for refs/pull/12, and says no reason.',
        'It keeps no coverage map for refs/pull/13, and says no reason.',
    ]);
});

it('says each way a store that leaves what it writes for deliver reads it back, or refuses it', function (): void {
    $refusing = storeAnsweringAlike(Ledger::empty(), Missing::at(Path::of('nowhere')), NotWritten::because('Read-only.'));

    expect([...ProofStoreContract::deliveringFailures(new ProofStoreFake())])->toBe([
        'It reads back proofs written for refs/pull/12, which it leaves for deliver.',
        'It reads back the coverage map kept beside refs/pull/12, which it leaves for deliver.',
    ])->and([...ProofStoreContract::deliveringFailures($refusing)])->toBe([
        'It keeps no ledger for refs/pull/12: Read-only.',
        'It keeps no coverage map for refs/pull/12: Read-only.',
    ]);
});

it('says each way a store holding a ledger it cannot read reads it anyway, or says the wrong reason', function (): void {
    $refused = Unreadable::because(UnreadReason::Refused, 'memory', 'denied');
    $handsOn = Unreadable::because(UnreadReason::Malformed, 'memory', 'not a ledger')->besides(ledgerOfAnotherUnit());

    expect([...ProofStoreContract::unreadableFailures(new ProofStoreFake())])
        ->toBe(['It reads the ledger of refs/heads/main, which holds no ledger.'])
        ->and([...ProofStoreContract::unreadableFailures(ProofStoreFake::unreadable($refused))])
        ->toBe(['It says the ledger of refs/heads/main is refused, where it is malformed.'])
        ->and([...ProofStoreContract::unreadableFailures(ProofStoreFake::unreadable($handsOn))])
        ->toBe(['It hands on proofs from the ledger of refs/heads/main, which it cannot read.']);
});
