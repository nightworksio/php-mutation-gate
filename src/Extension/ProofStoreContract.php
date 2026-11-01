<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/**
 * What every proof store answers (ADR-0001, ADR-0007, ADR-0023), for the
 * gate's own stores and an extension's to be held to alike. Each kind of
 * store is handed new, holding nothing yet, and each of its checks reads and
 * writes scopes of its own, so one store serves all of them.
 *
 * - `failures()`: a store that keeps what it is handed reads an empty ledger
 *   for a scope nothing wrote; reads back the ledger written to a pull
 *   request's scope, and nothing of it from the branch `main`; reads no
 *   coverage map beside a ledger where none was kept; and reads back the map
 *   kept beside a scope's ledger, byte for byte, and only that scope's.
 * - `readOnlyFailures()`: a store opened read-only reads an empty ledger and
 *   no map for a scope nothing wrote, and answers each ledger and map it is
 *   handed with why it keeps nothing, reading none of them back.
 * - `deliveringFailures()`: a store that leaves what it writes for `deliver`
 *   says it wrote each ledger and map it is handed, and reads what the store
 *   it reads holds, never what it wrote.
 * - `unreadableFailures()`: a store whose ledger for the branch `main` holds
 *   bytes that are no ledger says the ledger there is malformed, and hands
 *   on an empty ledger to judge with.
 */
final readonly class ProofStoreContract
{
    /** The branch no check writes to, whose scope each check reads as one nothing wrote. */
    private const string MAIN = 'main';

    /** The pull request whose scope the ledger is written to. */
    private const int LEDGER_REQUEST = 12;

    /** The pull request whose scope the coverage map is kept in. */
    private const int MAP_REQUEST = 13;

    private const string UNIT = 'src/Ledger.php';

    private const string RUN = 'github:1/1';

    private const string RAN_AT = '2026-09-29T20:48:17Z';

    private const string MAP = '{"format":1}';

    private const string NOT_READ = 'It reads no ledger for %s: %s';

    private const string NOT_EMPTY = 'It reads proofs for %s, which nothing wrote.';

    private const string HELD_MAP = 'It reads a coverage map beside %s, where none was kept.';

    private const string MAP_NOT_READ = 'It reads no coverage map beside %s: %s';

    private const string NOT_KEPT = 'It keeps no %s for %s: %s';

    private const string KEPT = 'It says it keeps the %s for %s, opened read-only.';

    private const string UNSAID = 'It keeps no %s for %s, and says no reason.';

    private const string DELIVERED = 'It reads back proofs written for %s, which it leaves for deliver.';

    private const string DELIVERED_MAP = 'It reads back the coverage map kept beside %s, which it leaves for deliver.';

    private const string NO_PROOF = 'It reads back no proof of %s for %s.';

    private const string NO_MAP = 'It reads back no coverage map beside %s.';

    private const string OTHER_MAP = 'It reads back another coverage map beside %s than was kept.';

    private const string LEDGER = 'ledger';

    private const string COVERAGE_MAP = 'coverage map';

    private function __construct(private ProofStore $store)
    {
    }

    /**
     * Every way a store that keeps what it is handed breaks the contract, each said as a sentence; none where it
     * keeps it.
     *
     * @return Listed<string>
     */
    public static function failures(ProofStore $store): Listed
    {
        $contract = new self($store);
        $main = Scope::branch(self::MAIN);
        $ledger = Scope::pullRequest(self::LEDGER_REQUEST);
        $map = Scope::pullRequest(self::MAP_REQUEST);

        return Listed::of(...[
            ...$contract->empty($main),
            ...$contract->noMap($main),
            ...$contract->writtenBack($ledger, $main),
            ...$contract->keptBack($map, $main),
        ]);
    }

    /**
     * Every way a store opened read-only breaks the contract, each said as a sentence; none where it keeps it.
     *
     * @return Listed<string>
     */
    public static function readOnlyFailures(ProofStore $store): Listed
    {
        $contract = new self($store);
        $main = Scope::branch(self::MAIN);
        $ledger = Scope::pullRequest(self::LEDGER_REQUEST);
        $map = Scope::pullRequest(self::MAP_REQUEST);

        return Listed::of(...[
            ...$contract->empty($main),
            ...$contract->noMap($main),
            ...$contract->refused(self::LEDGER, $ledger, $contract->store->write($ledger, self::ledger())),
            ...$contract->empty($ledger),
            ...$contract->refused(
                self::COVERAGE_MAP,
                $map,
                $contract->store->keep($map, Companion::Coverage, self::map()),
            ),
            ...$contract->noMap($map),
        ]);
    }

    /**
     * Every way a store that leaves what it writes for `deliver` breaks the contract, each said as a sentence;
     * none where it keeps it.
     *
     * @return Listed<string>
     */
    public static function deliveringFailures(ProofStore $store): Listed
    {
        $contract = new self($store);
        $main = Scope::branch(self::MAIN);
        $ledger = Scope::pullRequest(self::LEDGER_REQUEST);

        return Listed::of(...[
            ...$contract->empty($main),
            ...$contract->noMap($main),
            ...$contract->written(self::LEDGER, $ledger, $contract->store->write($ledger, self::ledger())),
            ...$contract->written(
                self::COVERAGE_MAP,
                $ledger,
                $contract->store->keep($ledger, Companion::Coverage, self::map()),
            ),
            ...$contract->empty($ledger, self::DELIVERED),
            ...$contract->noMap($ledger, self::DELIVERED_MAP),
        ]);
    }

    /**
     * Every way a store whose ledger for the branch `main` holds bytes that are no ledger breaks the contract,
     * each said as a sentence; none where it keeps it.
     *
     * @return Listed<string>
     */
    public static function unreadableFailures(ProofStore $store): Listed
    {
        $main = Scope::branch(self::MAIN);
        $read = $store->read($main);

        return Listed::of(...match (true) {
            ! $read instanceof Unreadable => [sprintf(
                'It reads the ledger of %s, which holds no ledger.',
                $main->ref(),
            )],
            $read->reason() !== UnreadReason::Malformed => [sprintf(
                'It says the ledger of %s is %s, where it is malformed.',
                $main->ref(),
                $read->reason()->value,
            )],
            $read->ledger()->proofs()->count() > 0 => [sprintf(
                'It hands on proofs from the ledger of %s, which it cannot read.',
                $main->ref(),
            )],
            default => [],
        });
    }

    /** A ledger holding one proof, of `src/Ledger.php`, at the base its run read. */
    private static function ledger(): Ledger
    {
        $at = Instant::parse(self::RAN_AT);
        $run = $at instanceof Instant ? Run::of(self::RUN, $at, Digest::sha256Of(self::RUN)) : $at;

        return $run instanceof Run ? self::proving($run) : Ledger::empty();
    }

    private static function proving(Run $run): Ledger
    {
        return Ledger::empty()
            ->withProof(Proof::of(self::key(), Path::of(self::UNIT), Mutants::none(), $run))
            ->atBase($run->base());
    }

    private static function key(): Digest
    {
        return Digest::sha256Of(self::UNIT);
    }

    private static function map(): Contents
    {
        return Contents::of(Gzip::pack(self::MAP));
    }

    /**
     * @param  string       $held what is said where it reads proofs, from the scope
     * @return list<string>
     */
    private function empty(Scope $scope, string $held = self::NOT_EMPTY): array
    {
        $read = $this->store->read($scope);

        return match (true) {
            $read instanceof Unreadable => [sprintf(self::NOT_READ, $scope->ref(), $read->why())],
            $read->proofs()->count() > 0 => [sprintf($held, $scope->ref())],
            default => [],
        };
    }

    /**
     * @param  string       $held what is said where it reads a map, from the scope
     * @return list<string>
     */
    private function noMap(Scope $scope, string $held = self::HELD_MAP): array
    {
        $map = $this->store->companion($scope, Companion::Coverage);

        return match (true) {
            $map instanceof Contents => [sprintf($held, $scope->ref())],
            $map instanceof CannotJudge => [sprintf(self::MAP_NOT_READ, $scope->ref(), $map->why())],
            default => [],
        };
    }

    /** @return list<string> */
    private function writtenBack(Scope $scope, Scope $other): array
    {
        $written = $this->store->write($scope, self::ledger());
        $read = $this->store->read($scope);

        return [
            ...$this->written(self::LEDGER, $scope, $written),
            ...match (true) {
                $read instanceof Unreadable => [sprintf(self::NOT_READ, $scope->ref(), $read->why())],
                ! $read->proofs()->has(self::key()) => [sprintf(self::NO_PROOF, self::UNIT, $scope->ref())],
                default => [],
            },
            ...$this->empty($other),
        ];
    }

    /** @return list<string> */
    private function keptBack(Scope $scope, Scope $other): array
    {
        $kept = $this->store->keep($scope, Companion::Coverage, self::map());
        $read = $this->store->companion($scope, Companion::Coverage);

        return [
            ...$this->written(self::COVERAGE_MAP, $scope, $kept),
            ...match (true) {
                $read instanceof Missing => [sprintf(self::NO_MAP, $scope->ref())],
                $read instanceof CannotJudge => [sprintf(self::MAP_NOT_READ, $scope->ref(), $read->why())],
                $read->text() !== self::map()->text() => [sprintf(self::OTHER_MAP, $scope->ref())],
                default => [],
            },
            ...$this->noMap($other),
            ...$this->empty($scope),
        ];
    }

    /** @return list<string> */
    private function refused(string $what, Scope $scope, Written|NotWritten $written): array
    {
        return match (true) {
            $written instanceof Written => [sprintf(self::KEPT, $what, $scope->ref())],
            $written->why() === '' => [sprintf(self::UNSAID, $what, $scope->ref())],
            default => [],
        };
    }

    /** @return list<string> */
    private function written(string $what, Scope $scope, Written|NotWritten $written): array
    {
        return $written instanceof NotWritten ? [sprintf(self::NOT_KEPT, $what, $scope->ref(), $written->why())] : [];
    }
}
