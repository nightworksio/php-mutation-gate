<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * A ledger per scope, as one object of a cloud's object API,
 * `<prefix>/<scope>/ledger.json.gz`, read and written with the token the API
 * hands out (ADR-0028 decision 1). An object that is not there is an empty
 * ledger. One that cannot be read, or a token that cannot be had, is
 * unreadable, which costs a run and never a verdict, and says why.
 */
final readonly class ObjectLedger
{
    private const string NO_TOKEN = 'no token: %s';

    private const string UNWRITTEN = '%s could not be written: no token: %s';


    private function __construct(
        private Exchange $exchange,
        private LedgerObject $objects,
        private LedgerLimits $limits,
    ) {
    }

    public static function under(Exchange $exchange, string $prefix): self
    {
        return new self($exchange, LedgerObject::under($prefix), LedgerLimits::standard());
    }

    /** Reading and writing ledgers within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return new self($this->exchange, $this->objects, $limits);
    }

    /** The scope's ledger in the store; an empty one where there is none yet; or why it could not be read. */
    public function read(ObjectStore $store, Scope $scope): Ledger|Unreadable
    {
        $key = $this->objects->of($scope);

        if ($key instanceof CannotJudge) {
            return Ledger::empty();
        }

        $at = $store->named($scope, $key);
        $token = $store->token();

        if (! $token instanceof Token) {
            return Unreadable::because(UnreadReason::Refused, $at, sprintf(self::NO_TOKEN, $token->why()));
        }

        $request = $store->reading($scope, LedgerObject::encoded($key), $token);
        $fetched = $this->exchange->fetch($request, $this->limits, $at);
        $read = is_string($fetched) ? LedgerFile::read($fetched, $this->limits) : $fetched;

        return $read instanceof Ledger || $read instanceof Unreadable ? $read : Unreadable::notRead($at, $read);
    }

    /** Write the scope's ledger to the store, saying where, or why not. */
    public function write(ObjectStore $store, Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $key = $this->objects->of($scope);

        if ($key instanceof CannotJudge) {
            return NotWritten::because($key->why());
        }

        $at = $store->named($scope, $key);
        $token = $store->token();

        if (! $token instanceof Token) {
            return NotWritten::because(sprintf(self::UNWRITTEN, $at, $token->why()));
        }

        $encoded = EncodedLedger::within($ledger, $this->limits);
        $request = $store->writing($scope, LedgerObject::encoded($key), $token, $encoded->bytes());
        $written = $this->exchange->put($request, $this->limits, $at);

        return $written instanceof Written ? $encoded->written($written) : $written;
    }

    /** The bytes of an object kept beside the scope's ledger; none where there is none yet; or why not. */
    public function companion(ObjectStore $store, Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        $key = $this->objects->companionOf($scope, $companion);

        if ($key instanceof CannotJudge) {
            return Missing::at(Path::of($companion->value));
        }

        $at = $store->named($scope, $key);
        $token = $store->token();

        if (! $token instanceof Token) {
            return CompanionRead::unread($companion, $at, sprintf(self::NO_TOKEN, $token->why()));
        }

        $request = $store->reading($scope, LedgerObject::encoded($key), $token);
        $fetched = $this->exchange->fetch($request, CompanionRead::limitsOf($companion), $at);

        return match (true) {
            is_string($fetched) => Contents::of($fetched),
            $fetched instanceof Unreadable => CompanionRead::unread($companion, $at, $fetched->detail()),
            default => Missing::at(Path::of($key)),
        };
    }

    /** Write an object beside the scope's ledger, saying where, or why not. */
    public function keep(ObjectStore $store, Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        $key = $this->objects->companionOf($scope, $companion);

        if ($key instanceof CannotJudge) {
            return NotWritten::because($key->why());
        }

        $at = $store->named($scope, $key);
        $token = $store->token();

        if (! $token instanceof Token) {
            return NotWritten::because(sprintf(self::UNWRITTEN, $at, $token->why()));
        }

        $request = $store->writing($scope, LedgerObject::encoded($key), $token, $bytes->text());

        return $this->exchange->put($request, CompanionRead::limitsOf($companion), $at);
    }
}
