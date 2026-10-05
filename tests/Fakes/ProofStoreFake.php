<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/** A proof store that keeps each scope's ledger in memory; or one that reads none, and says why. */
final class ProofStoreFake implements ProofStore
{
    /** @var array<string, Ledger> by scope */
    private array $ledgers = [];

    /** @var array<string, Contents> by scope and object */
    private array $companions = [];

    private Unreadable|NotGiven $unread;

    /** @var list<string> each scope asked for, as `read <ref>` or `write <ref>` */
    private array $asked = [];

    public function __construct()
    {
        $this->unread = NotGiven::value();
    }

    /** A store that reads no ledger, for this reason. */
    public static function unreadable(Unreadable $unread): self
    {
        $store = new self();
        $store->unread = $unread;

        return $store;
    }

    /** This store, already keeping this ledger as the scope's, which it was never asked to write. */
    public function keeping(Scope $scope, Ledger $ledger): self
    {
        $this->ledgers[$scope->ref()] = $ledger;

        return $this;
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        $this->asked[] = sprintf('read %s', $scope->ref());

        return match (true) {
            $this->unread instanceof Unreadable => $this->unread,
            array_key_exists($scope->ref(), $this->ledgers) => $this->ledgers[$scope->ref()],
            default => Ledger::empty(),
        };
    }

    public function write(Scope $scope, Ledger $ledger): Written
    {
        $this->asked[] = sprintf('write %s', $scope->ref());
        $this->ledgers[$scope->ref()] = $ledger;

        return Written::to(sprintf('memory:%s', $scope->ref()));
    }

    /**
     * Each scope this store was asked to read or write, in turn.
     *
     * @return list<string>
     */
    public function asked(): array
    {
        return $this->asked;
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing
    {
        $key = sprintf('%s/%s', $scope->ref(), $companion->value);

        return array_key_exists($key, $this->companions) ? $this->companions[$key] : Missing::at(Path::of($key));
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written
    {
        $key = sprintf('%s/%s', $scope->ref(), $companion->value);
        $this->companions[$key] = $bytes;

        return Written::to(sprintf('memory:%s', $key));
    }
}
