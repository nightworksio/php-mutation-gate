<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A forge's JSON REST API, asked through an exchange with a bearer token where there is one. A failure names the
 * API by its origin alone, never by a URL or the token.
 */
final readonly class RestApi
{
    private const string ACCEPT = 'Accept';

    private function __construct(private Exchange $exchange, private string $base, private Token|NotGiven $token)
    {
    }

    /** The API under this base URL, such as `https://gitlab.com/api/v4`, asked with this token or none. */
    public static function at(Exchange $exchange, string $base, Token|NotGiven $token): self
    {
        return new self($exchange, $base, $token);
    }

    /** The answer to a GET of a path under the base. */
    public function get(string $path): Reply|CannotJudge
    {
        return $this->exchange->answer($this->authorised(Request::get($this->url($path))));
    }

    /** The answer to posting this JSON document to a path under the base. */
    public function post(string $path, Json $document): Reply|CannotJudge
    {
        return $this->sent(Request::post($this->url($path), $document->line()));
    }

    /** The answer to putting this JSON document at a path under the base. */
    public function put(string $path, Json $document): Reply|CannotJudge
    {
        return $this->sent(Request::put($this->url($path), $document->line()));
    }

    /** The answer to deleting what a path under the base names. */
    public function delete(string $path): Reply|CannotJudge
    {
        return $this->exchange->answer($this->authorised(Request::delete($this->url($path))));
    }

    /** The JSON an accepted answer holds; or why there is none, a refusal with its status and the start of its body. */
    public function json(Reply|CannotJudge $answer): Node|CannotJudge
    {
        $body = Answer::body($answer, $this->named());

        return is_string($body) ? Node::decode($body, $this->named()) : $body;
    }

    /** What a failure calls the API: its origin. */
    public function named(): string
    {
        return Origin::of($this->base);
    }

    private function url(string $path): string
    {
        return sprintf('%s%s', $this->base, $path);
    }

    private function sent(Request $request): Reply|CannotJudge
    {
        return $this->exchange->answer($this->authorised($request->sending(MediaType::Json)));
    }

    private function authorised(Request $request): Request
    {
        $asking = $request->with(self::ACCEPT, MediaType::Json->value);

        return $this->token instanceof Token ? $asking->carrying($this->token) : $asking;
    }
}
