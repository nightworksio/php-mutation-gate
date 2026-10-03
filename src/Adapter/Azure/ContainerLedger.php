<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\Tokens;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
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
 * The proof store `azure`: one block blob per scope,
 * `<prefix>/<scope>/ledger.json.gz`, in a container of an Azure storage
 * account, read with a GET and written with a PUT of the Blob service, each
 * carrying the token OIDC federation gives (ADR-0028 decisions 1 and 2).
 * The default branch's scope is kept in the `publicContainer`, where one is
 * named and the default branch is known, so a job without credentials can
 * read it; every other scope is kept in the private container (decision 4).
 */
final readonly class ContainerLedger implements ObjectStore, ProofStore
{
    private const string BLOB = 'https://%s.blob.core.windows.net/%s/%s';

    /** The Blob service's version the requests are written against, one that takes a bearer token. */
    private const string VERSION = '2024-11-04';

    private const string VERSION_HEADER = 'x-ms-version';

    private const string TYPE_HEADER = 'x-ms-blob-type';

    private const string BLOCK_BLOB = 'BlockBlob';

    private function __construct(
        private ObjectLedger $ledgers,
        private string $account,
        private string $container,
        private string|NotGiven $publicContainer,
        private Scope|NotGiven $defaultBranch,
        private Tokens $tokens,
    ) {
    }

    /** The private container of this account. */
    public static function of(
        Exchange $exchange,
        string $account,
        string $container,
        string $prefix,
        Tokens $tokens,
    ): self {
        return new self(
            ObjectLedger::under($exchange, $prefix),
            $account,
            $container,
            NotGiven::value(),
            NotGiven::value(),
            $tokens,
        );
    }

    /** From the store's options, with the token the environment leads to; or why either is refused. */
    public static function configured(Options $options, Variables $environment, Exchange $exchange): self|Invalid
    {
        $container = ContainerOptions::read($options);
        $tokens = FederatedCredential::from($environment, $exchange);

        return match (true) {
            $container instanceof Invalid => $container,
            $tokens instanceof Invalid => $tokens,
            default => self::of(
                $exchange,
                $container->account(),
                $container->container(),
                $container->prefix(),
                $tokens,
            )->publishing($container->publicContainer()),
        };
    }

    /** This store, keeping the default branch's scope in this container, which anyone may read. */
    public function publishing(string|NotGiven $container): self
    {
        return clone($this, ['publicContainer' => $container]);
    }

    /** This store, knowing which scope is the default branch's. */
    public function forDefaultBranch(Scope $scope): self
    {
        return clone($this, ['defaultBranch' => $scope]);
    }

    /** Reading and writing ledgers within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return clone($this, ['ledgers' => $this->ledgers->within($limits)]);
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        return $this->ledgers->read($this, $scope);
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        return $this->ledgers->write($this, $scope, $ledger);
    }

    public function token(): Token|CannotJudge
    {
        return $this->tokens->token();
    }

    public function named(Scope $scope, string $key): string
    {
        return sprintf(self::BLOB, $this->account, $this->containerOf($scope), $key);
    }

    public function reading(Scope $scope, string $path, Token $token): Request
    {
        return Request::get(sprintf(self::BLOB, $this->account, $this->containerOf($scope), $path))
            ->carrying($token)
            ->with(self::VERSION_HEADER, self::VERSION);
    }

    public function writing(Scope $scope, string $path, Token $token, string $bytes): Request
    {
        return Request::put(sprintf(self::BLOB, $this->account, $this->containerOf($scope), $path), $bytes)
            ->carrying($token)
            ->sending(MediaType::Gzip)
            ->with(self::VERSION_HEADER, self::VERSION)
            ->with(self::TYPE_HEADER, self::BLOCK_BLOB);
    }

    /** The container a scope's ledger is kept in: the public one for the default branch's, where both are known. */
    private function containerOf(Scope $scope): string
    {
        return is_string($this->publicContainer)
            && $this->defaultBranch instanceof Scope
            && $this->defaultBranch->equals($scope)
            ? $this->publicContainer
            : $this->container;
    }
}
