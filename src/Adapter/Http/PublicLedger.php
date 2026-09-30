<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Http;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rtrim;
use function sprintf;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
    /** The answer for a ledger that is not there yet. */
    private const int NOT_FOUND = 404;

    private const string READ_ONLY = 'read-only: no credentials; this run\'s proofs are not kept. %s';

    private const string READ_FROM = 'The default branch\'s ledger is read from %s.';

    private const string READ_NOWHERE = 'No publicUrl names where the default branch\'s ledger is read from.';

    private function __construct(
        private HttpClientInterface $client,
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
            $client,
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
            $client,
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
        $fetched = $this->fetched($url);
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

    /** The bytes at this URL; an empty ledger where none is there yet; or why they could not be read. */
    private function fetched(string $url): string|Ledger|Unreadable
    {
        try {
            $response = $this->client->request(
                'GET',
                $url,
                ['max_duration' => $this->limits->seconds(), 'max_redirects' => 0],
            );
            $status = $response->getStatusCode();

            return match (true) {
                $status === self::NOT_FOUND => Ledger::empty(),
                Reply::of($status, '', '')->isAccepted() => $this->bodyOf($response, $url),
                default => Unreadable::because(UnreadReason::Refused, $url, sprintf('HTTP %d', $status)),
            };
        } catch (TimeoutExceptionInterface) {
            return Unreadable::because(UnreadReason::TimedOut, $url, 'no answer came in time');
        } catch (ExceptionInterface $unreached) {
            return Unreadable::because(
                UnreadReason::Unreachable,
                $url,
                Fit::line(Fit::plain($unreached->getMessage()), Reply::ANSWER),
            );
        }
    }

    /**
     * The body as it streams in; or, once it passes the limit, why it is not read, when the response, no longer
     * held, stops.
     *
     * @throws ExceptionInterface
     */
    private function bodyOf(ResponseInterface $response, string $url): string|Unreadable
    {
        $bytes = '';

        foreach ($this->client->stream($response) as $chunk) {
            $bytes .= $chunk->getContent();

            if (! $this->limits->admitsPacked(Bytes::length($bytes))) {
                $response->cancel();

                return Unreadable::because(UnreadReason::TooLarge, $url, $this->limits->pastPacked());
            }
        }

        return $bytes;
    }
}
