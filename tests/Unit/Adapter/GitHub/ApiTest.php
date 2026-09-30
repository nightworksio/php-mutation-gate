<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Answer;
use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

it('asks GitHub at a path of its API with a token, and reads the answer', function (): void {
    $response = new JsonMockResponse(['sha' => 'abc']);
    $answer = Api::at(new MockHttpClient($response), 'https://github.example/api/v3', 'secret')->get('/repos/octo/gate/commits/abc');

    expect($answer)->toBeInstanceOf(Answer::class)
        ->and($answer instanceof Answer ? $answer->text('sha') : '')->toBe('abc')
        ->and($response->getRequestMethod())->toBe('GET')
        ->and($response->getRequestUrl())->toBe('https://github.example/api/v3/repos/octo/gate/commits/abc')
        ->and($response->getRequestOptions()['headers'])->toContain(
            'Accept: application/vnd.github+json',
            'Authorization: Bearer secret',
            'X-GitHub-Api-Version: 2022-11-28',
        );
});

it("asks GitHub's own API where no other is named", function (): void {
    $response = new JsonMockResponse(['sha' => 'abc']);
    Api::at(new MockHttpClient($response), '', 'secret')->get('/repos/octo/gate/commits/abc');

    expect($response->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/commits/abc');
});

it('cannot tell what GitHub did not answer', function (): void {
    $answer = Api::at(new MockHttpClient(new MockResponse('{"message": "Not Found"}', ['http_code' => 404])), 'https://api.github.com', 'secret')
        ->get('/repos/octo/gate/commits/abc');

    expect($answer)->toBeInstanceOf(CannotTell::class)
        ->and($answer instanceof CannotTell ? $answer->why() : '')->toContain('404');
});

it('cannot tell from an answer that is not JSON', function (): void {
    $answer = Api::at(new MockHttpClient(new MockResponse('<html>')), 'https://api.github.com', 'secret')->get('/rate_limit');

    expect($answer)->toBeInstanceOf(CannotTell::class);
});

it('sends a JSON body with a method, and reads the answer', function (): void {
    $response = new JsonMockResponse(['id' => 9]);
    $answer = Api::at(new MockHttpClient($response), 'https://api.github.com', 'secret')
        ->send('PATCH', '/repos/octo/gate/issues/comments/9', ['body' => 'text']);

    expect($answer instanceof Answer ? $answer->number('id') : 0)->toBe(9)
        ->and($response->getRequestMethod())->toBe('PATCH')
        ->and($response->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/issues/comments/9')
        ->and($response->getRequestOptions()['body'])->toBe('{"body":"text"}')
        ->and($response->getRequestOptions()['headers'])->toContain('Authorization: Bearer secret');
});

it('asks without a token rather than with an empty one, which GitHub refuses', function (): void {
    $response = new JsonMockResponse(['sha' => 'abc']);
    Api::at(new MockHttpClient($response), '', '')->get('/repos/octo/gate/commits/abc');

    $headers = (string) json_encode($response->getRequestOptions()['headers']);

    expect(str_contains($headers, 'Authorization'))->toBeFalse()
        ->and($headers)->toContain('X-GitHub-Api-Version');
});
