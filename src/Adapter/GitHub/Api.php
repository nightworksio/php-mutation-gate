<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** GitHub's REST API, asked with a token. */
final readonly class Api
{
    /** The version of the API the answers are read as. */
    private const string VERSION = '2022-11-28';

    /** The media type of an answer as JSON. */
    private const string JSON = 'application/vnd.github+json';

    /** The media type of a file's contents as they are. */
    private const string RAW = 'application/vnd.github.raw';

    /** GitHub's own API, which a run on an Enterprise server names another in place of. */
    private const string GITHUB = 'https://api.github.com';

    private function __construct(private HttpClientInterface $client, private string $url, private string $token)
    {
    }

    /** The API at a URL such as `GITHUB_API_URL` gives, or GitHub's own where it gives none. */
    public static function at(HttpClientInterface $client, string $url, string $token): self
    {
        return new self($client, $url === '' ? self::GITHUB : $url, $token);
    }

    /**
     * What GitHub answers at a path of its API, or why it did not. Without a
     * token the request goes unauthenticated, as a public repository allows,
     * rather than with an empty one, which GitHub refuses.
     */
    public function get(string $path): Answer|CannotTell
    {
        return $this->ask('GET', $path, []);
    }

    /** What a file holds as GitHub's API serves it raw at a path, such as a repository's contents; or why not. */
    public function raw(string $path): string|CannotTell
    {
        try {
            return $this->client->request('GET', sprintf('%s%s', $this->url, $path), [
                'headers' => $this->headers(self::RAW),
            ])->getContent();
        } catch (ExceptionInterface $unanswered) {
            return CannotTell::because($unanswered->getMessage());
        }
    }

    /**
     * What GitHub answers when a JSON body is sent to a path of its API with a
     * method such as `POST` or `PATCH`, or why it did not.
     *
     * @param array<string, string> $body
     */
    public function send(string $method, string $path, array $body): Answer|CannotTell
    {
        return $this->ask($method, $path, ['json' => $body]);
    }

    /** @param array{json?: array<string, string>} $options */
    private function ask(string $method, string $path, array $options): Answer|CannotTell
    {
        try {
            return Answer::of($this->client->request($method, sprintf('%s%s', $this->url, $path), [
                ...$options,
                'headers' => $this->headers(self::JSON),
            ])->toArray());
        } catch (ExceptionInterface $unanswered) {
            return CannotTell::because($unanswered->getMessage());
        }
    }

    /**
     * The headers of every request: the media type asked for, the API's
     * version, and the token, where there is one.
     *
     * @return array<string, string>
     */
    private function headers(string $accept): array
    {
        return [
            'Accept' => $accept,
            'X-GitHub-Api-Version' => self::VERSION,
            ...$this->token === '' ? [] : ['Authorization' => sprintf('Bearer %s', $this->token)],
        ];
    }
}
