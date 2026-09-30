<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function mb_strlen;
use function mb_substr;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** GitHub's API as a test scripts it: an answer at each path, as often as asked, and 404 anywhere else. */
final readonly class GitHubAnswering
{
    private const string API = 'https://api.github.com';

    /** @param array<string, MockResponse> $answers each answer, by its path under GitHub's own API */
    public static function client(array $answers): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($answers): ResponseInterface {
            $path = mb_substr($url, mb_strlen(self::API));

            return array_key_exists($path, $answers)
                ? $answers[$path]
                : new MockResponse('{"message": "Not Found"}', ['http_code' => 404]);
        });
    }
}
