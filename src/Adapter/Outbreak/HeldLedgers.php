<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_keys;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\ProofEntry;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Serve\ServesState;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The ledgers `watch` holds between its rounds where `outbreak serve` keeps
 * proofs (ADR-0133): each read from the store once, and a round's ledger
 * held in place of the one it read, the proofs it gained kept and those it
 * dropped forgotten by the daemon, which journals both, instead of the
 * ledger written to the store. A round that changes what the ledger tells
 * of its runs, or whose proofs the daemon does not keep or forget, writes
 * the store as it does without the daemon; a key the daemon was not made
 * to forget is forgotten with the next. Once `watch` ends, each ledger a
 * round left held is written over what the store holds then: its proofs,
 * timings, bases and lessons before the store's, none the rounds dropped,
 * and the runs the store tells of.
 *
 * @internal
 */
final class HeldLedgers implements ProofStore
{
    /** @var array<string, Ledger> each ledger held, by its scope's ref */
    private array $held = [];

    /** @var array<string, Scope> each scope whose held ledger the store does not hold, by its ref */
    private array $unwritten = [];

    /** @var array<string, array<string, Digest>> the keys of the proofs the rounds dropped, by their scope's ref */
    private array $dropped = [];

    /** @var list<string> the keys of dropped proofs the daemon was not made to forget */
    private array $unforgotten = [];

    public function __construct(private readonly ProofStore $store, private readonly ServesState $state)
    {
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        $held = $this->held[$scope->ref()] ?? null;

        if ($held instanceof Ledger) {
            return $held;
        }

        $read = $this->store->read($scope);

        if ($read instanceof Ledger) {
            $this->held[$scope->ref()] = $read;
        }

        return $read;
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $held = $this->held[$scope->ref()] ?? null;
        $dropped = $held instanceof Ledger ? self::droppedFrom($held, $ledger) : [];
        $forgot = $this->forgetting(...array_keys($dropped));

        if (! $held instanceof Ledger || ! $ledger->runs()->tellsAs($held->runs()) || $forgot instanceof NotWritten) {
            return $this->written($scope, $ledger);
        }

        $gained = [];

        foreach ($ledger->proofs() as $proof) {
            if (! $held->proofs()->has($proof->key())) {
                $gained[$proof->key()->value()] = ProofEntry::text($proof);
            }
        }

        $kept = $this->state->kept($gained);

        if ($kept instanceof NotWritten) {
            return $this->written($scope, $ledger);
        }

        $this->held[$scope->ref()] = $ledger;
        $this->unwritten[$scope->ref()] = $scope;
        $this->dropped[$scope->ref()] = [...($this->dropped[$scope->ref()] ?? []), ...$dropped];

        return $kept;
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        return $this->store->companion($scope, $companion);
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        return $this->store->keep($scope, $companion, $bytes);
    }

    /**
     * Each ledger a round left held written to the store, over what the
     * store holds then; why the first that was not was not; nothing where
     * none was left.
     */
    public function released(): Written|NotWritten|NotGiven
    {
        $forgot = $this->forgetting();
        $released = $forgot instanceof NotWritten ? $forgot : NotGiven::value();

        foreach ($this->unwritten as $scope) {
            $wrote = $this->written($scope, $this->over($scope));
            $released = $released instanceof NotWritten ? $released : $wrote;
        }

        return $released;
    }

    /** The ledger held of a scope, over the store's as it reads now, without the proofs the rounds dropped. */
    private function over(Scope $scope): Ledger
    {
        $read = $this->store->read($scope);
        $stored = $read instanceof Unreadable ? $read->ledger() : $read;

        foreach ($this->dropped[$scope->ref()] ?? [] as $key) {
            $stored = $stored->withoutProof($key);
        }

        return $this->held[$scope->ref()]->and($stored)->withRuns($stored->runs());
    }

    /**
     * The proofs of a held ledger a round's ledger no longer holds, by key.
     *
     * @return array<string, Digest>
     */
    private static function droppedFrom(Ledger $held, Ledger $ledger): array
    {
        $dropped = [];

        foreach ($held->proofs() as $proof) {
            if (! $ledger->proofs()->has($proof->key())) {
                $dropped[$proof->key()->value()] = $proof->key();
            }
        }

        return $dropped;
    }

    /**
     * These keys forgotten by the daemon, with every key it was not yet
     * made to forget; or why they were not, and each kept to forget later.
     */
    private function forgetting(string ...$keys): Written|NotWritten|NotGiven
    {
        $forget = [...$this->unforgotten, ...$keys];

        if ($forget === []) {
            return NotGiven::value();
        }

        $forgot = $this->state->forgotten(...$forget);
        $this->unforgotten = $forgot instanceof Written ? [] : $forget;

        return $forgot;
    }

    private function written(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $wrote = $this->store->write($scope, $ledger);

        if ($wrote instanceof Written) {
            $this->held[$scope->ref()] = $ledger;
            unset($this->unwritten[$scope->ref()], $this->dropped[$scope->ref()]);
        }

        return $wrote;
    }
}
