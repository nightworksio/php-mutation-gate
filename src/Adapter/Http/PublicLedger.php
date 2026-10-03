<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Http;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rtrim;
use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A store a job without its credentials opens read-only (ADR-0013 decisions
 * 13 and 14): the default branch's ledger is read with an anonymous GET from
 * the public URL the store's options name, at
 * `<publicUrl>/<prefix>/<scope>/ledger.json.gz`, each segment percent-encoded,
 * and none is written. No other scope is public, so none other is asked for,
 * and where no public URL is named, nothing is. A 404 is a scope with no
 * ledger yet. Any other answer, a request that fails or outlasts the limits'
 * seconds, a body past their bytes, or one that is no ledger this gate reads,
 * is unreadable, and says why.
 */
final readonly class PublicLedger implements ProofStore
{
    private const string READ_ONLY = 'read-only: no credentials; this run\'s proofs are not kept. %s';

    private const string READ_FROM = 'The default branch\'s ledger is read from %s.';

    private const string READ_NOWHERE = 'No publicUrl names where the default branch\'s ledger is read from.';

    private function __construct(
        private HttpExchange $exchange,
        private string|NotGiven $url,
        private LedgerObject $objects,
        private LedgerLimits $limits,
        private Scope|NotGiven $readable,
    ) {
    }

    /** Reading from this public URL, the ledgers under this prefix. */
    public static function at(HttpClientInterface $client, string $url, string $prefix): self
    {
        return new self(
            HttpExchange::over($client),
            rtrim($url, '/'),
            LedgerObject::under($prefix),
            LedgerLimits::standard(),
            NotGiven::value(),
        );
    }

    /** Reading nothing, as no public URL is named. */
    public static function nowhere(HttpClientInterface $client): self
    {
        return new self(
            HttpExchange::over($client),
            NotGiven::value(),
            LedgerObject::under(''),
            LedgerLimits::standard(),
            NotGiven::value(),
        );
    }

    /** Reading within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return clone($this, ['limits' => $limits]);
    }

    /** Reading this scope's ledger alone, the default branch's, the only one a public URL serves. */
    public function onlyReading(Scope $scope): self
    {
        return clone($this, ['readable' => $scope]);
    }

    public function read(Scope $scope): Ledger|Unreadable
    {
        $path = $this->objects->path($scope);
        $unread = $this->readable instanceof Scope && ! $this->readable->equals($scope);

        if ($this->url instanceof NotGiven || $path instanceof CannotJudge || $unread) {
            return Ledger::empty();
        }

        $url = sprintf('%s/%s', $this->url, $path);
        $fetched = $this->exchange->fetch(Request::get($url), $this->limits, $url);
        $read = is_string($fetched) ? LedgerFile::read($fetched, $this->limits) : $fetched;

        return $read instanceof Ledger || $read instanceof Unreadable ? $read : Unreadable::notRead($url, $read);
    }

    /** Nothing: without credentials, no request is made, and the run says so once. */
    public function write(Scope $scope, Ledger $ledger): NotWritten
    {
        return NotWritten::because(sprintf(
            self::READ_ONLY,
            $this->url instanceof NotGiven ? self::READ_NOWHERE : sprintf(self::READ_FROM, $this->url),
        ));
    }
}
