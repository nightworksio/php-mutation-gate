<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Http;

use function mb_strlen;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rtrim;
use function sprintf;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A store a job without its credentials opens read-only (ADR-0013 decisions
 * 13 and 14): each ledger is read with an anonymous GET from the public URL
 * the store's options name, at `<publicUrl>/<prefix>/<scope>/ledger.json.gz`,
 * and none is written. Where no public URL is named, nothing is read either.
 * A 404 is a scope with no ledger yet; any other refusal, a request that
 * fails or outlasts a minute, or a body past 256 MiB reads as an empty
 * ledger, which costs a run and never a verdict.
 */
final readonly class PublicLedger implements ProofStore
{
    /** The longest one read may take, in seconds: a ledger doctor calls slow is 25 MB. */
    private const float TIMEOUT = 60.0;

    /** The most bytes a ledger is read to, where no fewer are asked for, so an answer without end cannot hold a run. */
    private const int LARGEST = 268_435_456;

    private const string READ_ONLY = 'read-only: no credentials; this run\'s proofs are not kept. %s';

    private const string READ_FROM = 'The ledgers are read from %s.';

    private const string READ_NOWHERE = 'No publicUrl names where the default branch\'s ledger is read from.';

    private function __construct(
        private HttpClientInterface $client,
        private string|NotGiven $url,
        private LedgerObject $objects,
        private int $largest,
    ) {
    }

    /** Reading from this public URL, the ledgers under this prefix. */
    public static function at(HttpClientInterface $client, string $url, string $prefix): self
    {
        return new self($client, rtrim($url, '/'), LedgerObject::under($prefix), self::LARGEST);
    }

    /** Reading nothing, as no public URL is named. */
    public static function nowhere(HttpClientInterface $client): self
    {
        return new self($client, NotGiven::value(), LedgerObject::under(''), self::LARGEST);
    }

    /** Reading a ledger to no more than this many bytes. */
    public function atMost(int $bytes): self
    {
        return new self($this->client, $this->url, $this->objects, $bytes);
    }

    public function read(Scope $scope): Ledger
    {
        $key = $this->objects->of($scope);

        return $this->url instanceof NotGiven || $key instanceof CannotJudge
            ? Ledger::empty()
            : LedgerFile::decode($this->fetched(sprintf('%s/%s', $this->url, $key)));
    }

    /** Nothing: without credentials, no request is made, and the run says so once. */
    public function write(Scope $scope, Ledger $ledger): NotWritten
    {
        return NotWritten::because(sprintf(
            self::READ_ONLY,
            $this->url instanceof NotGiven ? self::READ_NOWHERE : sprintf(self::READ_FROM, $this->url),
        ));
    }

    /** The bytes at this URL where it answers with at most as many as a ledger is read to; none otherwise. */
    private function fetched(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url, ['max_duration' => self::TIMEOUT, 'max_redirects' => 0]);

            $accepted = Reply::of($response->getStatusCode(), '', '')->isAccepted();

            return $accepted ? $this->bodyOf($response) : '';
        } catch (ExceptionInterface) {
            return '';
        }
    }

    /**
     * The body as it streams in, or none once it passes the most a ledger is read to, when the response, no longer
     * held, stops.
     *
     * @throws ExceptionInterface
     */
    private function bodyOf(ResponseInterface $response): string
    {
        $bytes = '';

        foreach ($this->client->stream($response) as $chunk) {
            $bytes .= $chunk->getContent();

            if (mb_strlen($bytes, '8bit') > $this->largest) {
                return '';
            }
        }

        return $bytes;
    }
}
