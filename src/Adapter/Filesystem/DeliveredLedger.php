<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/**
 * The proofs of a run under `--deliver-later` (ADR-0007 decision 5): read from the store the config names, which
 * `fetch` filled, and written beside the run's delivery for `deliver` to write to the store, so the run holds none
 * of the store's keys.
 */
final readonly class DeliveredLedger implements ProofStore
{
    /** What a run says of the ledger it left, so it never reads as one the store holds. */
    private const string LEFT = 'It is not in the store until deliver writes it there for %s.';

    /** What a run says of an object it left to keep beside a ledger, so it never reads as one the store keeps. */
    private const string LEFT_KEPT = 'It is not in the store until deliver keeps it there for %s.';

    private function __construct(private ProofStore $read, private DeliveryDirectory $delivery)
    {
    }

    /** Reading from this store, writing beside this delivery. */
    public static function over(ProofStore $read, DeliveryDirectory $delivery): self
    {
        return new self($read, $delivery);
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        return $this->read->read($scope);
    }

    /** The scope's ledger, beside the delivery; the store is never written. */
    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $written = $this->delivery->ledger($scope, $ledger);

        return $written instanceof Written
            ? Written::sayingFirst(sprintf(self::LEFT, $scope->ref()), $written)
            : NotWritten::because($written->why());
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        return $this->read->companion($scope, $companion);
    }

    /** The object, beside the delivery; the store is never written. */
    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        $kept = $this->delivery->kept($scope, $companion, $bytes);

        return $kept instanceof Written
            ? Written::sayingFirst(sprintf(self::LEFT_KEPT, $scope->ref()), $kept)
            : NotWritten::because($kept->why());
    }
}
