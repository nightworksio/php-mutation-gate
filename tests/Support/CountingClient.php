<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Generator;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An HTTP client that hands on another's answers and counts each chunk of
 * a body its caller takes, so a test can tell a read that stopped from one
 * that took everything.
 */
final class CountingClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    public private(set) int $taken = 0;

    /** @param array<array-key, mixed> $options Symfony's request options, handed on unread */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return new AsyncResponse($this->client, $method, $url, $options, $this->counted(...));
    }

    private function counted(ChunkInterface $chunk, AsyncContext $context): Generator
    {
        $this->taken++;

        yield $chunk;
    }
}
