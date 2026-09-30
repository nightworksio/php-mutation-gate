<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotWritten;

use function preg_replace;
use function sprintf;

/**
 * What a service answered a post, a chat's, a webhook's or a collector's:
 * its status, its `Retry-After`, and its body, and how a report says it
 * was refused.
 */
final readonly class Reply
{
    private const int TOO_MANY = 429;

    private const int SERVER = 500;

    private const int OK = 200;

    private const int REDIRECT = 300;

    /** The most characters of an answer a refusal repeats. */
    private const int ANSWER = 200;

    private const string REFUSED = '%s answered %d: %s';

    private const string UNREACHED = '%s could not be reached: %s';

    /** Any URL, which a failure never repeats, as a chat's holds its credential. */
    private const string URL = '#https?://[^\s"\']+#i';

    private function __construct(private int $status, private string $retryAfter, private string $body)
    {
    }

    public static function of(int $status, string $retryAfter, string $body): self
    {
        return new self($status, $retryAfter, $body);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** The `Retry-After` header as it came; empty where there was none. */
    public function retryAfter(): string
    {
        return $this->retryAfter;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isAccepted(): bool
    {
        return $this->status >= self::OK && $this->status < self::REDIRECT;
    }

    /** Whether it asks to be tried again: too many requests, or a server's error. */
    public function isWorthRetrying(): bool
    {
        return $this->status === self::TOO_MANY || $this->status >= self::SERVER;
    }

    /** Why a post to what the reader knows as this was not written: its status, and its answer as one plain line. */
    public function refusedBy(string $to): NotWritten
    {
        $answer = Fit::line(Fit::plain($this->body), self::ANSWER);

        return NotWritten::because(sprintf(self::REFUSED, $to, $this->status, $answer));
    }

    /**
     * Why a post to what the reader knows as this was not written: it could
     * not be reached. Every URL in the reason becomes that name, as a chat's
     * URL holds its credential, and the reason is one plain line.
     */
    public static function unreached(string $to, string $why): NotWritten
    {
        return NotWritten::because(sprintf(self::UNREACHED, $to, Fit::plain(preg_replace(self::URL, $to, $why) ?? '')));
    }
}
