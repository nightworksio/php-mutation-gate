<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_map;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\KeptPost;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\EncodedLedger;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The delivery a command under `--deliver-later` leaves for `deliver`, in its stage's directory under
 * `.mutation-gate/delivery` (ADR-0007 decision 5): `delivery.json`, begun empty so nothing an earlier run left there
 * is sent again and added to as the run goes, and the ledger, `ledger.json.gz`, and each object a store keeps
 * beside a ledger, such as the coverage map, `coverage.json.gz`, beside it.
 */
final readonly class DeliveryDirectory
{
    private function __construct(private Directory $directory)
    {
    }

    /** The delivery this stage leaves in this project. */
    public static function of(Directory $project, Stage $stage): self
    {
        return new self(Directory::at($project->root()->at($stage->directory())->value()));
    }

    /** The delivery, emptied: no payload, and no ledger or object kept beside it. */
    public function begun(): Written|CannotJudge
    {
        $kept = array_map(static fn(Companion $companion): string => $companion->value, Companion::cases());
        $names = [LedgerFile::NAME, ...$kept];

        foreach ($names as $name) {
            $removed = $this->directory->remove(Path::of($name));

            if ($removed instanceof CannotJudge) {
                return $removed;
            }
        }

        return $this->written(Delivery::none());
    }

    /**
     * The delivery, with what this adds to it.
     *
     * @param Closure(Delivery): Delivery $add
     */
    public function adding(Closure $add): Written|CannotJudge
    {
        $read = $this->directory->readAtMost(Path::of(DeliveryFile::NAME), LedgerLimits::standard()->packed());
        $delivery = match (true) {
            $read instanceof Contents => DeliveryFile::decode($read->text()),
            $read instanceof Missing => Delivery::none(),
            $read instanceof TooLarge => CannotJudge::because($read->why()),
            default => $read,
        };

        return $delivery instanceof Delivery ? $this->written($add($delivery)) : $delivery;
    }

    /**
     * The delivery, with this ledger beside it for this scope, which `deliver` writes to the store for it: written as
     * a store writes it, within the limits a run reads a ledger to, saying where and what it dropped to stay within.
     */
    public function ledger(Scope $scope, Ledger $ledger): Written|CannotJudge
    {
        $encoded = EncodedLedger::within($ledger, LedgerLimits::standard());
        $file = $this->directory->write(Path::of(LedgerFile::NAME), Contents::of($encoded->bytes()));
        $post = LedgerPost::to($scope);

        if ($file instanceof CannotJudge) {
            return $file;
        }

        $added = $this->adding(static fn(Delivery $delivery): Delivery => $delivery->withLedger($post));

        return $added instanceof Written ? $encoded->written($file) : $added;
    }

    /**
     * The delivery, with this object beside it, as the store would keep it beside the scope's ledger, which
     * `deliver` keeps in the store for that scope.
     */
    public function kept(Scope $scope, Companion $companion, Contents $bytes): Written|CannotJudge
    {
        $file = $this->directory->write(Path::of($companion->value), $bytes);

        if ($file instanceof CannotJudge) {
            return $file;
        }

        $post = KeptPost::of($companion, $scope);
        $added = $this->adding(static fn(Delivery $delivery): Delivery => $delivery->withKept($post));

        return $added instanceof Written ? $file : $added;
    }

    private function written(Delivery $delivery): Written|CannotJudge
    {
        return $this->directory->write(Path::of(DeliveryFile::NAME), Contents::of(DeliveryFile::encode($delivery)));
    }
}
