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

    private function __construct(private HttpClientInterface $client, private string $url, private string $token)
    {
    }

    public static function at(HttpClientInterface $client, string $url, string $token): self
    {
        return new self($client, $url, $token);
    }

    /** What GitHub answers at a path of its API, or why it did not. */
    public function get(string $path): Answer|CannotTell
    {
        return $this->ask('GET', $path, []);
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
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => sprintf('Bearer %s', $this->token),
                    'X-GitHub-Api-Version' => self::VERSION,
                ],
            ])->toArray());
        } catch (ExceptionInterface $unanswered) {
            return CannotTell::because($unanswered->getMessage());
        }
    }
}
