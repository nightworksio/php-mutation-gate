<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function explode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A cloud's object API and token endpoints as the gcs and azure stores
 * see them: objects kept by URL, a PUT keeping its body, a GET of an object
 * that is not there answered 404, and canned answers for the URLs a test
 * names. Every request is recorded, with its headers.
 */
final class Cloud
{
    /** @var list<array{method: string, url: string, body: string, headers: array<string, string>}> */
    public private(set) array $requests = [];

    /** @var array<string, string> each object, by its URL */
    private array $objects = [];

    /** @var array<string, array{int, string}> the status and body each named URL answers */
    private array $answers = [];

    public function __construct(private readonly int $failing = 0)
    {
    }

    public function exchange(): HttpExchange
    {
        return HttpExchange::over($this->client());
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->respond(...));
    }

    /** Put an object in the cloud, as another run would have. */
    public function holding(string $url, string $object): self
    {
        $this->objects[$url] = $object;

        return $this;
    }

    /** Answer every request to this URL with this status and body. */
    public function answering(string $url, int $status, string $body): self
    {
        $this->answers[$url] = [$status, $body];

        return $this;
    }

    /** @param array<array-key, mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $this->headers($options)];

        if (array_key_exists($url, $this->answers)) {
            [$status, $answer] = $this->answers[$url];

            return new MockResponse($answer, ['http_code' => $status]);
        }

        return match (true) {
            $this->failing !== 0 => new MockResponse('', ['http_code' => $this->failing]),
            $method === 'PUT' => $this->kept($url, $body),
            array_key_exists($url, $this->objects) => new MockResponse($this->objects[$url], ['http_code' => 200]),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function kept(string $url, string $body): MockResponse
    {
        $this->objects[$url] = $body;

        return new MockResponse('', ['http_code' => 201]);
    }

    /**
     * @param  array<array-key, mixed> $options
     * @return array<string, string>   each header's first value, by its lower-case name
     */
    private function headers(array $options): array
    {
        $headers = [];

        foreach (is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [] as $name => $lines) {
            $line = is_array($lines) && is_string($lines[0] ?? null) ? $lines[0] : '';
            $headers[(string) $name] = explode(': ', $line, 2)[1] ?? '';
        }

        return $headers;
    }
}
