<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\HeldLedgers;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\KeepsProofsFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;

/** A ledger holding a proof of each of these units, by one run. */
function heldLedger(string ...$units): Ledger
{
    $run = Run::of('run-1', Instant::at(new DateTimeImmutable('2026-11-01T00:00:00Z')), Digest::sha256Of('base'));
    $ledger = Ledger::empty();

    foreach ($units as $unit) {
        $ledger = $ledger->withProof(Proof::of(Digest::sha256Of($unit), Path::of($unit), Mutants::none(), $run));
    }

    return $ledger->atBase($run->base());
}

/**
 * Which of these units a ledger holds a proof of, as the store reads it now.
 *
 * @return list<string>
 */
function heldIn(ProofStoreFake $store, Scope $scope, string ...$units): array
{
    $read = $store->read($scope);
    $proofs = $read instanceof Ledger ? $read->proofs() : $read->ledger()->proofs();

    return array_values(array_filter($units, static fn(string $unit): bool => $proofs->has(Digest::sha256Of($unit))));
}

it('holds a round\'s ledger between rounds, the proofs it gained kept and those it dropped forgotten by the daemon, and writes it to the store once released', function (): void {
    $scope = Scope::branch('feature');
    $store = new ProofStoreFake();
    $store->write($scope, heldLedger('src/A.php', 'src/B.php'));
    $state = KeepsProofsFake::running();
    $held = new HeldLedgers($store, $state);

    $held->read($scope);
    $kept = $held->write($scope, heldLedger('src/A.php', 'src/C.php'));
    $before = heldIn($store, $scope, 'src/A.php', 'src/B.php', 'src/C.php');
    $daemon = $state->proofs(Digest::sha256Of('src/B.php')->value(), Digest::sha256Of('src/C.php')->value());
    $released = $held->released();

    expect($kept)->toBeInstanceOf(Written::class)
        ->and($before)->toBe(['src/A.php', 'src/B.php'])
        ->and(is_array($daemon) ? array_keys($daemon) : $daemon)->toBe([Digest::sha256Of('src/C.php')->value()])
        ->and($released)->toBeInstanceOf(Written::class)
        ->and(heldIn($store, $scope, 'src/A.php', 'src/B.php', 'src/C.php'))->toBe(['src/A.php', 'src/C.php'])
        ->and($held->released())->toBeInstanceOf(NotGiven::class);
});

it('holds a round\'s ledger whose runs moved, and writes the runs the newest round told of once released', function (): void {
    $scope = Scope::branch('feature');
    $store = new ProofStoreFake();
    $store->write($scope, heldLedger('src/A.php'));
    $held = new HeldLedgers($store, KeepsProofsFake::running());
    $passed = Passed::of(Revision::ref('5eeca8f'), 'mutation / verdict', 0);
    $newest = Passed::of(Revision::ref('6ffdb90'), 'mutation / verdict', 0);

    $held->read($scope);
    $held->write($scope, heldLedger('src/A.php')->withRuns(ScopeRuns::none()->passing($passed)));
    $held->write($scope, heldLedger('src/A.php', 'src/B.php')->withRuns(ScopeRuns::none()->passing($newest)));
    $before = $store->read($scope);
    $held->released();
    $after = $store->read($scope);

    expect($before instanceof Ledger ? $before->runs()->passed() : $before)->toBeInstanceOf(CannotTell::class)
        ->and($store->asked())->toBe(['write refs/heads/feature', 'read refs/heads/feature', 'read refs/heads/feature', 'read refs/heads/feature', 'write refs/heads/feature', 'read refs/heads/feature'])
        ->and($after instanceof Ledger ? $after->runs()->passed() : $after)->toBe($newest)
        ->and(heldIn($store, $scope, 'src/A.php', 'src/B.php'))->toBe(['src/A.php', 'src/B.php']);
});

it('writes a round\'s ledger to the store at once where the daemon keeps no proof, or the store\'s was never read', function (bool $read, bool $both): void {
    $scope = Scope::branch('feature');
    $store = new ProofStoreFake();
    $store->write($scope, heldLedger('src/A.php'));
    $held = new HeldLedgers($store, $read ? KeepsProofsFake::none() : KeepsProofsFake::running());

    if ($read) {
        $held->read($scope);
    }

    $units = $both ? ['src/A.php', 'src/C.php'] : ['src/C.php'];
    $written = $held->write($scope, heldLedger(...$units));
    $again = $held->read($scope);

    expect($written)->toBeInstanceOf(Written::class)
        ->and(heldIn($store, $scope, 'src/A.php', 'src/C.php'))->toBe($units)
        ->and($again instanceof Ledger && $again->proofs()->has(Digest::sha256Of('src/C.php')))->toBeTrue();
})->with([
    'the daemon keeps none' => [true, true],
    'never read' => [false, false],
]);

it('says why the daemon forgot nothing a round dropped, once released', function (): void {
    $scope = Scope::branch('feature');
    $store = new ProofStoreFake();
    $store->write($scope, heldLedger('src/A.php', 'src/B.php'));
    $held = new HeldLedgers($store, KeepsProofsFake::none());

    $held->read($scope);
    $held->write($scope, heldLedger('src/A.php'));

    expect($held->released())->toBeInstanceOf(NotWritten::class);
});

it('reads and keeps a scope\'s companions in the store it holds the ledgers of', function (): void {
    $scope = Scope::branch('feature');
    $store = new ProofStoreFake();
    $held = new HeldLedgers($store, KeepsProofsFake::running());

    $kept = $held->keep($scope, Companion::Coverage, Contents::of('map'));
    $read = $held->companion($scope, Companion::Coverage);

    expect($kept)->toBeInstanceOf(Written::class)
        ->and($read instanceof Contents ? $read->text() : $read)->toBe('map')
        ->and($store->companion($scope, Companion::Coverage))->toEqual($read);
});
