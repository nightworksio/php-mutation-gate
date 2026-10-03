<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

/**
 * A request to send: its method, its URL, its headers and its body. Its
 * headers may hold a credential, so nothing prints a request.
 */
final readonly class Request
{
    private const string AUTHORIZATION = 'Authorization';

    private const string CONTENT_TYPE = 'Content-Type';

    /** @param array<string, string> $headers by name */
    private function __construct(
        private Method $method,
        private string $url,
        private array $headers,
        private string $body,
    ) {
    }

    public static function get(string $url): self
    {
        return new self(Method::Get, $url, [], '');
    }

    public static function put(string $url, string $body): self
    {
        return new self(Method::Put, $url, [], $body);
    }

    public static function post(string $url, string $body): self
    {
        return new self(Method::Post, $url, [], $body);
    }

    /** This request, with a header set to this value. */
    public function with(string $header, string $value): self
    {
        return new self($this->method, $this->url, [...$this->headers, $header => $value], $this->body);
    }

    /** This request, carrying a bearer token. */
    public function carrying(Token $token): self
    {
        return $this->with(self::AUTHORIZATION, $token->authorization());
    }

    /** This request, saying its body is of this type. */
    public function sending(MediaType $type): self
    {
        return $this->with(self::CONTENT_TYPE, $type->value);
    }

    public function method(): Method
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return array<string, string> by name */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}
