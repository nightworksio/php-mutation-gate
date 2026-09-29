<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\S3\S3Client;

use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Extension\Options;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An S3 bucket in memory, behind a stand-in HTTP client: it keeps what is put
 * in it, serves it back, answers NoSuchKey for anything else, and records
 * every request. One made failing answers every request with that status.
 */
final class Bucket
{
    public const string NO_SUCH_KEY = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>
        XML;

    /** @var list<array{method: string, url: string, body: string, type: string}> */
    public private(set) array $requests = [];

    /** @var array<string, string> each object, by its URL */
    private array $objects = [];

    public function __construct(private readonly int $failing = 0)
    {
    }

    /** A client of this bucket, configured as the store's options say. */
    public function client(string $options = '{"bucket": "ledgers", "region": "eu-west-1"}'): S3Client
    {
        $bucket = BucketOptions::read(Options::ofJson($options));

        $configuration = $bucket instanceof BucketOptions ? $bucket->configuration() : [];

        return new S3Client(
            [...$configuration, 'accessKeyId' => 'key', 'accessKeySecret' => 'secret'],
            new ConfigurationProvider(),
            new MockHttpClient($this->respond(...)),
        );
    }

    /** Put an object in the bucket, as another run would have. */
    public function holding(string $url, string $object): self
    {
        $this->objects[$url] = $object;

        return $this;
    }

    /** @param array<array-key, mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $headers = $options['normalized_headers'] ?? [];
        $types = is_array($headers) && is_array($headers['content-type'] ?? null) ? $headers['content-type'] : [];
        $type = $types[0] ?? '';
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'type' => is_string($type) ? $type : '',
        ];

        if ($this->failing !== 0) {
            return new MockResponse('', ['http_code' => $this->failing]);
        }

        if ($method === 'PUT') {
            $this->objects[$url] = $body;

            return new MockResponse('', ['http_code' => 200]);
        }

        return array_key_exists($url, $this->objects)
            ? new MockResponse($this->objects[$url], ['http_code' => 200])
            : new MockResponse(self::NO_SUCH_KEY, ['http_code' => 404]);
    }
}
