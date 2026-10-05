<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\S3\S3Client;

use function is_array;
use function is_float;
use function is_string;

use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

use function sprintf;

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

    /** @var list<float|null> the most seconds each request was allowed */
    public private(set) array $durations = [];

    /** @var array<string, string|iterable<string>> each object, by its URL: whole, or the chunks it streams in */
    private array $objects = [];

    /** @var array<string, int> the size each object says it has, where it says one, by its URL */
    private array $lengths = [];

    public function __construct(private readonly int $failing = 0)
    {
    }

    /** A client of this bucket, configured as the store's options say. */
    public function client(string $options = '{"bucket": "ledgers", "region": "eu-west-1"}'): S3Client
    {
        $bucket = BucketOptions::read(Configs::builtin(Builtins::stores(ProjectRoot::origin()), 's3', $options));

        $configuration = $bucket instanceof BucketOptions ? $bucket->configuration() : [];

        return new S3Client(
            [...$configuration, 'accessKeyId' => 'key', 'accessKeySecret' => 'secret'],
            new ConfigurationProvider(),
            $this->http(),
        );
    }

    /** The stand-in HTTP client the bucket answers through. */
    public function http(): MockHttpClient
    {
        return new MockHttpClient($this->respond(...));
    }

    /**
     * Put an object in the bucket, as another run would have: whole, or as the chunks it streams in.
     *
     * @param string|iterable<string> $object
     */
    public function holding(string $url, string|iterable $object): self
    {
        $this->objects[$url] = $object;

        return $this;
    }

    /**
     * Put an object in the bucket that says it has this many bytes, whatever it holds.
     *
     * @param string|iterable<string> $object
     */
    public function saying(string $url, string|iterable $object, int $length): self
    {
        $this->lengths[$url] = $length;

        return $this->holding($url, $object);
    }

    /** @param array<array-key, mixed> $options */
    private function record(string $method, string $url, array $options, string $body): void
    {
        $headers = $options['normalized_headers'] ?? [];
        $types = is_array($headers) && is_array($headers['content-type'] ?? null) ? $headers['content-type'] : [];
        $type = $types[0] ?? '';
        $duration = $options['max_duration'] ?? null;
        $this->durations[] = is_float($duration) ? $duration : null;
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'type' => is_string($type) ? $type : '',
        ];
    }

    /** @param array<array-key, mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->record($method, $url, $options, $body);

        if ($this->failing !== 0) {
            return new MockResponse('', ['http_code' => $this->failing]);
        }

        if ($method === 'PUT') {
            $this->objects[$url] = $body;

            return new MockResponse('', ['http_code' => 200]);
        }

        $length = array_key_exists($url, $this->lengths) ? [sprintf('Content-Length: %d', $this->lengths[$url])] : [];

        return array_key_exists($url, $this->objects)
            ? new MockResponse($this->objects[$url], ['http_code' => 200, 'response_headers' => $length])
            : new MockResponse(self::NO_SUCH_KEY, ['http_code' => 404]);
    }
}
