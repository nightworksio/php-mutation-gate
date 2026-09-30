<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use function array_key_exists;

use Closure;
use DateTimeImmutable;

use function implode;
use function is_numeric;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One alert posted within 10 seconds, or reported unsent: a 429 or a 5xx is
 * tried once more after its `Retry-After`, up to 30 seconds, and then the
 * reporter says *not written* with the status and the body (ADR-0016,
 * decision 13). A redirect is not followed, so the body and its signature
 * go nowhere but the URL given, and a failure never repeats that URL, which
 * holds the chat's credential.
 */
final readonly class Delivery
{
    /** The longest one request may take, in seconds. */
    private const float TIMEOUT = 10.0;

    /** The longest a `Retry-After` is waited for, in seconds. */
    private const int LONGEST_WAIT = 30;

    /** How HTTP writes a date, as `Wed, 30 Sep 2026 12:00:30 GMT`. */
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /** @param Closure(int): void $wait waits this many seconds */
    private function __construct(
        private HttpClientInterface $client,
        private ClockInterface $clock,
        private Closure $wait,
    ) {
    }

    /** @param Closure(int): void $wait waits this many seconds */
    public static function over(HttpClientInterface $client, ClockInterface $clock, Closure $wait): self
    {
        return new self($client, $clock, $wait);
    }

    /** Over the network, waiting in real time. */
    public static function online(ClockInterface $clock): self
    {
        return new self(HttpClient::create(), $clock, Pause::for(...));
    }

    /**
     * Post this body to this URL, named to the reader as this.
     *
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers, string $to): Written|NotWritten
    {
        $reply = $this->send($url, $body, $headers);

        if ($reply instanceof Reply && $reply->isWorthRetrying()) {
            ($this->wait)($this->waitFor($reply));
            $reply = $this->send($url, $body, $headers);
        }

        return match (true) {
            $reply instanceof NotWritten => Reply::unreached($to, $reply->why()),
            $reply->isAccepted() => Written::to($to),
            default => $reply->refusedBy($to),
        };
    }

    /** @param array<string, string> $headers */
    private function send(string $url, string $body, array $headers): Reply|NotWritten
    {
        try {
            $response = $this->client->request('POST', $url, [
                'body' => $body,
                'headers' => $headers,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $answered = $response->getHeaders(throw: false);

            return Reply::of(
                $response->getStatusCode(),
                array_key_exists('retry-after', $answered) ? implode(',', $answered['retry-after']) : '',
                $response->getContent(throw: false),
            );
        } catch (ExceptionInterface $unreached) {
            return NotWritten::because($unreached->getMessage());
        }
    }

    /** The seconds a reply asks to be waited, as a number or a date, never more than 30. */
    private function waitFor(Reply $reply): int
    {
        $after = $reply->retryAfter();
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $after);
        $seconds = match (true) {
            is_numeric($after) => (int) $after,
            $date instanceof DateTimeImmutable => $date->getTimestamp() - $this->clock->now()->getTimestamp(),
            default => 0,
        };

        return min(max(0, $seconds), self::LONGEST_WAIT);
    }
}
