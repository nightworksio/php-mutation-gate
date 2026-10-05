<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\Tokens;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\ObjectLedger;
use NightWorksIO\MutationGate\Core\Proof\ObjectStore;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/**
 * The proof store `gcs`: one object per scope, `<prefix>/<scope>/ledger.json.gz`,
 * in a Cloud Storage bucket, read with a GET and written with a PUT of its
 * XML API, each carrying the token OIDC federation gives (ADR-0028
 * decisions 1 and 2).
 */
final readonly class BucketLedger implements ObjectStore, ProofStore
{
    private const string OBJECT = 'https://storage.googleapis.com/%s/%s';

    private const string NAMED = 'gs://%s/%s';

    private function __construct(private ObjectLedger $ledgers, private string $bucket, private Tokens $tokens)
    {
    }

    public static function of(Exchange $exchange, string $bucket, string $prefix, Tokens $tokens): self
    {
        return new self(ObjectLedger::under($exchange, $prefix), $bucket, $tokens);
    }

    /** From the store's options, with the token the environment leads to; or why either is refused. */
    public static function configured(Options $options, Variables $environment, Exchange $exchange): self|Invalid
    {
        $bucket = GcsOptions::read($options);
        $tokens = Federation::from($environment, $exchange);

        return match (true) {
            $bucket instanceof Invalid => $bucket,
            $tokens instanceof Invalid => $tokens,
            default => self::of($exchange, $bucket->bucket(), $bucket->prefix(), $tokens),
        };
    }

    /** Reading and writing ledgers within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return new self($this->ledgers->within($limits), $this->bucket, $this->tokens);
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        return $this->ledgers->read($this, $scope);
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        return $this->ledgers->write($this, $scope, $ledger);
    }

    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        return $this->ledgers->companion($this, $scope, $companion);
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        return $this->ledgers->keep($this, $scope, $companion, $bytes);
    }

    public function token(): Token|CannotJudge
    {
        return $this->tokens->token();
    }

    public function named(Scope $scope, string $key): string
    {
        return sprintf(self::NAMED, $this->bucket, $key);
    }

    public function reading(Scope $scope, string $path, Token $token): Request
    {
        return Request::get(sprintf(self::OBJECT, $this->bucket, $path))->carrying($token);
    }

    public function writing(Scope $scope, string $path, Token $token, string $bytes): Request
    {
        return Request::put(sprintf(self::OBJECT, $this->bucket, $path), $bytes)
            ->carrying($token)
            ->sending(MediaType::Gzip);
    }
}
