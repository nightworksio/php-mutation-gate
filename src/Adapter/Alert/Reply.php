<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

/** What a chat or webhook answered a post: its status, its `Retry-After`, and its body. */
final readonly class Reply
{
    private const int TOO_MANY = 429;

    private const int SERVER = 500;

    private const int OK = 200;

    private const int REDIRECT = 300;

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
}
