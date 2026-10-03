<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Http;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A store's requests, sent through `symfony/http-client`, each within the
 * ledger limits' seconds and following no redirect. Every body is read as
 * it streams in, and dropped once it passes the limits' bytes. A
 * failure is told by the name the reader knows the place by, never by the
 * URL, which may hold more than a reader should see.
 */
final readonly class HttpExchange implements Exchange
{
    /** The answer for a ledger that is not there yet. */
    private const int NOT_FOUND = 404;

    private const string UNREAD_ANSWER = '%s answered, and its answer was not read: %s';

    private function __construct(private HttpClientInterface $client, private LedgerLimits $limits)
    {
    }

    public static function over(HttpClientInterface $client): self
    {
        return new self($client, LedgerLimits::standard());
    }

    public function answer(Request $request): Reply|CannotJudge
    {
        try {
            return $this->replied($this->sent($request, $this->limits), $this->limits, Origin::of($request->url()));
        } catch (ExceptionInterface $unreached) {
            return CannotJudge::because(Reply::unreached(Origin::of($request->url()), $unreached->getMessage())->why());
        }
    }

    public function fetch(Request $request, LedgerLimits $limits, string $from): string|Ledger|Unreadable
    {
        try {
            $response = $this->sent($request, $limits);
            $status = $response->getStatusCode();
            $accepted = Reply::of($status, '', '')->isAccepted();

            return match (true) {
                $status === self::NOT_FOUND => Ledger::empty(),
                $accepted => $this->ledgerBytes($this->bodyOf($response, $limits), $limits, $from),
                default => Unreadable::because(UnreadReason::Refused, $from, sprintf('HTTP %d', $status)),
            };
        } catch (TimeoutExceptionInterface) {
            return Unreadable::because(UnreadReason::TimedOut, $from, 'no answer came in time');
        } catch (ExceptionInterface $unreached) {
            return Unreadable::because(
                UnreadReason::Unreachable,
                $from,
                Fit::line(Fit::plain($unreached->getMessage()), Reply::ANSWER),
            );
        }
    }

    public function put(Request $request, LedgerLimits $limits, string $to): Written|NotWritten
    {
        try {
            $reply = $this->replied($this->sent($request, $limits), $limits, $to);

            return match (true) {
                $reply instanceof CannotJudge => NotWritten::because($reply->why()),
                $reply->isAccepted() => Written::to($to),
                default => $reply->refusedBy($to),
            };
        } catch (ExceptionInterface $unreached) {
            return Reply::unreached($to, $unreached->getMessage());
        }
    }

    /** @throws ExceptionInterface */
    private function sent(Request $request, LedgerLimits $limits): ResponseInterface
    {
        return $this->client->request($request->method()->value, $request->url(), [
            'headers' => $request->headers(),
            'body' => $request->body(),
            'max_duration' => $limits->seconds(),
            'max_redirects' => 0,
        ]);
    }

    /**
     * The answer, its body read within the limits as it streams in; or, past them, why it is not read.
     *
     * @throws ExceptionInterface
     */
    private function replied(ResponseInterface $response, LedgerLimits $limits, string $from): Reply|CannotJudge
    {
        $status = $response->getStatusCode();
        $body = $this->bodyOf($response, $limits);

        return is_string($body)
            ? Reply::of($status, '', $body)
            : CannotJudge::because(sprintf(self::UNREAD_ANSWER, $from, $limits->pastPacked()));
    }

    /** A ledger's bytes; or, where they passed the limit, why they are not read. */
    private function ledgerBytes(string|NotGiven $body, LedgerLimits $limits, string $from): string|Unreadable
    {
        return is_string($body) ? $body : Unreadable::because(UnreadReason::TooLarge, $from, $limits->pastPacked());
    }

    /**
     * The body as it streams in; or nothing, once it passes the limit, when the response, no longer held, stops.
     *
     * @throws ExceptionInterface
     */
    private function bodyOf(ResponseInterface $response, LedgerLimits $limits): string|NotGiven
    {
        $bytes = '';

        foreach ($this->client->stream($response) as $chunk) {
            $bytes .= $chunk->getContent();

            if (! $limits->admitsPacked(Bytes::length($bytes))) {
                $response->cancel();

                return NotGiven::value();
            }
        }

        return $bytes;
    }
}
